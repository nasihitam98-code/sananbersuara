<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Backup terjadwal (K27): dicek tiap 15 menit; backup dibuat bila sudah waktunya.
 */
#[Signature('pemilihan:backup {--sekarang : Paksa backup sekarang}')]
#[Description('Backup database + foto calon (terenkripsi) sesuai jadwal')]
class RunScheduledBackup extends Command
{
    public function handle(BackupService $backups): int
    {
        $backup = $this->option('sekarang') ? $backups->create('MANUAL_KONSOL') : $backups->runScheduled();

        if ($backup === null) {
            $this->info('Belum waktunya backup.');

            return self::SUCCESS;
        }

        $this->line("Backup {$backup->status}: {$backup->filename} {$backup->error}");

        return $backup->status === 'BERHASIL' ? self::SUCCESS : self::FAILURE;
    }
}
