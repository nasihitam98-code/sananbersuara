<?php

namespace App\Services\Backups;

use App\Enums\ElectionStatus;
use App\Models\Backup;
use App\Models\Election;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InternalNotifier;
use App\Services\Voting\VotingException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Backup & restore (K27): dump database + foto calon dalam zip terenkripsi AES-256,
 * disimpan lokal dan (bila dikonfigurasi) disalin ke disk off-server. Restore dikontrol ketat.
 */
class BackupService
{
    public function __construct(
        private AuditLogger $audit,
        private InternalNotifier $notifier,
    ) {}

    public function create(string $trigger, ?User $actor = null): Backup
    {
        $backup = new Backup;
        $backup->forceFill([
            'public_id' => (string) Str::ulid(),
            'status' => Backup::STATUS_RUNNING,
            'trigger' => $trigger,
            'created_by' => $actor?->id,
        ])->save();

        $workDir = storage_path('app/private/backup-work/'.$backup->public_id);
        File::ensureDirectoryExists($workDir);

        try {
            $sqlPath = $workDir.'/database.sql';
            $this->dumpDatabase($sqlPath);

            $filename = 'backup-'.Carbon::now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.zip';
            $zipPath = $workDir.'/'.$filename;
            $this->zip($zipPath, $sqlPath);

            $disk = Storage::disk(config('voting.backup.disk'));
            $target = config('voting.backup.directory').'/'.$filename;
            $disk->put($target, fopen($zipPath, 'r'));

            $offsite = $this->copyOffsite($target, $zipPath);

            $backup->forceFill([
                'filename' => $filename,
                'size_bytes' => filesize($zipPath),
                'checksum' => hash_file('sha256', $zipPath),
                'status' => Backup::STATUS_SUCCESS,
                'offsite_copied' => $offsite,
            ])->save();

            $this->audit->log('backup.created', $backup, meta: ['trigger' => $trigger, 'size' => $backup->size_bytes, 'offsite' => $offsite], actor: $actor, actorType: $actor === null ? 'system' : 'user');
        } catch (Throwable $exception) {
            $backup->forceFill(['status' => Backup::STATUS_FAILED, 'error' => Str::limit($exception->getMessage(), 1000)])->save();

            Log::error('Backup gagal', ['backup' => $backup->public_id, 'error' => $exception->getMessage()]);
            $this->audit->log('backup.failed', $backup, meta: ['trigger' => $trigger], actor: $actor, actorType: $actor === null ? 'system' : 'user');
            $this->notifier->notifySuperAdmins('Backup GAGAL', 'Backup '.$trigger.' gagal: '.Str::limit($exception->getMessage(), 200));
        } finally {
            File::deleteDirectory($workDir);
        }

        return $backup;
    }

    /**
     * Restore: hanya Super Admin, ketik "PULIHKAN", tidak saat pemilihan berlangsung,
     * otomatis membuat backup sebelum restore. Jejak restore juga ditulis ke file log terpisah
     * karena audit log di database ikut kembali ke titik backup.
     */
    public function restore(Backup $backup, User $actor, string $confirmation): void
    {
        abort_unless($actor->isSuperAdmin(), 403);

        if (trim($confirmation) !== 'PULIHKAN') {
            throw VotingException::invalidState('Ketik PULIHKAN (huruf kapital) untuk melanjutkan.');
        }

        if (Election::query()->whereIn('status', [ElectionStatus::Berlangsung, ElectionStatus::Paused])->exists()) {
            throw VotingException::invalidState('Restore ditolak: ada pemilihan yang sedang berlangsung.');
        }

        if ($backup->status !== Backup::STATUS_SUCCESS) {
            throw VotingException::invalidState('Backup ini tidak berhasil dan tidak bisa dipulihkan.');
        }

        $disk = Storage::disk(config('voting.backup.disk'));
        $source = config('voting.backup.directory').'/'.$backup->filename;

        if (! $disk->exists($source) || hash('sha256', (string) $disk->get($source)) !== $backup->checksum) {
            throw VotingException::invalidState('File backup tidak ditemukan atau checksum tidak cocok.');
        }

        $safety = $this->create('SEBELUM_RESTORE', $actor);

        if ($safety->status !== Backup::STATUS_SUCCESS) {
            throw VotingException::invalidState('Backup pengaman sebelum restore gagal. Restore dibatalkan.');
        }

        $this->audit->log('backup.restore_started', $backup, meta: ['safety_backup' => $safety->public_id], actor: $actor);
        $this->restoreLog("MULAI restore {$backup->filename} oleh {$actor->email}; backup pengaman {$safety->filename}");

        $workDir = storage_path('app/private/backup-work/restore-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($workDir);

        try {
            $zipPath = $workDir.'/backup.zip';
            File::put($zipPath, (string) $disk->get($source));

            $zip = new ZipArchive;

            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('Zip backup tidak bisa dibuka.');
            }

            $zip->setPassword($this->password());

            if (! $zip->extractTo($workDir, 'database.sql')) {
                throw new RuntimeException('Isi backup tidak bisa dibaca (password salah?).');
            }

            $zip->close();
            $this->importDatabase($workDir.'/database.sql');
            Cache::flush();
        } catch (Throwable $exception) {
            $this->restoreLog('GAGAL: '.$exception->getMessage());

            throw VotingException::invalidState('Restore gagal: '.Str::limit($exception->getMessage(), 200));
        } finally {
            File::deleteDirectory($workDir);
        }

        // Tabel riwayat backup ikut kembali ke titik lama; catatan backup pengaman dimasukkan lagi.
        if (! Backup::query()->where('public_id', $safety->public_id)->exists()) {
            (new Backup)->forceFill($safety->only(['public_id', 'filename', 'size_bytes', 'checksum', 'status', 'trigger', 'offsite_copied']))->save();
        }

        $this->restoreLog("SELESAI restore {$backup->filename}");
        $this->audit->log('backup.restored', meta: ['file' => $backup->filename, 'by' => $actor->email, 'safety_backup' => $safety->filename], actor: null, actorType: 'system');
        $this->notifier->notifySuperAdmins('Restore dilakukan', "{$actor->name} memulihkan database dari {$backup->filename}.");
    }

    /**
     * Backup terjadwal: tiap 15 menit saat ada pemilihan berlangsung, tiap 6 jam di luar itu.
     */
    public function runScheduled(): ?Backup
    {
        $live = Election::query()->whereIn('status', [ElectionStatus::Berlangsung, ElectionStatus::Paused])->exists();
        $interval = $live ? (int) config('voting.backup.live_interval_minutes') : (int) config('voting.backup.idle_interval_minutes');

        $last = Backup::query()->where('status', Backup::STATUS_SUCCESS)->latest()->first();

        if ($last !== null && $last->created_at->greaterThan(Carbon::now()->subMinutes($interval))) {
            return null;
        }

        $backup = $this->create('TERJADWAL');
        $this->cleanup();

        return $backup;
    }

    /**
     * Menghapus backup lebih tua dari masa simpan (default 30 hari).
     */
    public function cleanup(): int
    {
        $old = Backup::query()->where('created_at', '<', Carbon::now()->subDays((int) config('voting.backup.keep_days')))->get();
        $disk = Storage::disk(config('voting.backup.disk'));

        foreach ($old as $backup) {
            if ($backup->filename !== null) {
                $disk->delete(config('voting.backup.directory').'/'.$backup->filename);
            }

            $backup->delete();
        }

        return $old->count();
    }

    public function absolutePath(Backup $backup): string
    {
        return Storage::disk(config('voting.backup.disk'))->path(config('voting.backup.directory').'/'.$backup->filename);
    }

    private function dumpDatabase(string $path): void
    {
        $connection = config('database.connections.'.config('database.default'));

        $result = Process::env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')])
            ->timeout(600)
            ->run([
                config('voting.backup.mysqldump'),
                '--host='.$connection['host'],
                '--port='.$connection['port'],
                '--user='.$connection['username'],
                '--single-transaction',
                '--routines',
                '--triggers',
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                '--result-file='.$path,
                $connection['database'],
            ]);

        if (! $result->successful() || ! File::exists($path) || File::size($path) === 0) {
            throw new RuntimeException('mysqldump gagal: '.Str::limit(trim($result->errorOutput()), 300));
        }
    }

    private function importDatabase(string $path): void
    {
        $connection = config('database.connections.'.config('database.default'));

        $result = Process::env(['MYSQL_PWD' => (string) ($connection['password'] ?? '')])
            ->timeout(1200)
            ->input(File::get($path))
            ->run([
                config('voting.backup.mysql'),
                '--host='.$connection['host'],
                '--port='.$connection['port'],
                '--user='.$connection['username'],
                '--default-character-set=utf8mb4',
                $connection['database'],
            ]);

        if (! $result->successful()) {
            throw new RuntimeException('Import database gagal: '.Str::limit(trim($result->errorOutput()), 300));
        }
    }

    private function zip(string $zipPath, string $sqlPath): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak bisa membuat file zip backup.');
        }

        $password = $this->password();
        $zip->setPassword($password);
        $zip->addFile($sqlPath, 'database.sql');
        $zip->setEncryptionName('database.sql', ZipArchive::EM_AES_256, $password);

        $photos = Storage::disk(config('voting.photo.disk'));

        foreach ($photos->files(config('voting.photo.directory')) as $photo) {
            $entry = 'foto/'.basename($photo);
            $zip->addFile($photos->path($photo), $entry);
            $zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $password);
        }

        if (! $zip->close()) {
            throw new RuntimeException('Gagal menulis zip backup.');
        }
    }

    private function copyOffsite(string $target, string $zipPath): bool
    {
        $offsite = config('voting.backup.offsite_disk');

        if (blank($offsite)) {
            return false;
        }

        try {
            return Storage::disk($offsite)->put($target, fopen($zipPath, 'r'));
        } catch (Throwable $exception) {
            Log::warning('Salinan backup off-server gagal', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    private function password(): string
    {
        $password = (string) config('voting.backup.password');

        if ($password === '') {
            throw new RuntimeException('BACKUP_PASSWORD belum diatur di .env.');
        }

        return $password;
    }

    private function restoreLog(string $message): void
    {
        File::append(storage_path('logs/restore.log'), '['.Carbon::now()->toDateTimeString().'] '.$message.PHP_EOL);
    }
}
