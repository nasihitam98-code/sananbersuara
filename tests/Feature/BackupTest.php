<?php

namespace Tests\Feature;

use App\Filament\Pages\BackupPage;
use App\Models\Backup;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\User;
use App\Services\Backups\BackupService;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\VotingException;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_file((string) config('voting.backup.mysqldump')) && config('voting.backup.mysqldump') !== 'mysqldump') {
            $this->markTestSkipped('mysqldump tidak tersedia di mesin ini.');
        }

        config(['voting.backup.password' => 'kata-sandi-backup-uji', 'voting.backup.directory' => 'backups-uji']);
        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->forceFill(['has_email_authentication' => true])->save();
        $this->superAdmin->assignRole(User::ROLE_SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('backups-uji');

        parent::tearDown();
    }

    public function test_backup_is_an_encrypted_zip_with_database_dump(): void
    {
        $backup = app(BackupService::class)->create('MANUAL', $this->superAdmin);

        $this->assertSame(Backup::STATUS_SUCCESS, $backup->status, (string) $backup->error);
        $path = app(BackupService::class)->absolutePath($backup);
        $this->assertFileExists($path);
        $this->assertSame($backup->checksum, hash_file('sha256', $path));

        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertFalse(@$zip->getFromName('database.sql'), 'Tanpa password isi tidak bisa dibaca');

        $zip->setPassword('kata-sandi-backup-uji');
        $sql = (string) $zip->getFromName('database.sql');
        $zip->close();

        $this->assertStringContainsString('CREATE TABLE `audit_logs`', $sql);
        $this->assertStringContainsString('audit_logs_block_update', $sql, 'Trigger append-only ikut terbackup');
    }

    public function test_failed_backup_is_recorded_and_notified(): void
    {
        config(['voting.backup.mysqldump' => 'C:/tidak/ada/mysqldump.exe']);
        $other = User::factory()->create();
        $other->assignRole(User::ROLE_SUPER_ADMIN);

        $backup = app(BackupService::class)->create('TERJADWAL');

        $this->assertSame(Backup::STATUS_FAILED, $backup->status);
        $this->assertTrue($other->notifications()->where('data', 'like', '%Backup GAGAL%')->exists());
    }

    public function test_scheduled_backup_respects_interval(): void
    {
        $service = app(BackupService::class);

        $this->assertNotNull($service->runScheduled());
        $this->assertNull($service->runScheduled(), 'Belum 6 jam');

        $this->travel(361)->minutes();
        $this->assertNotNull($service->runScheduled());
    }

    public function test_restore_guards(): void
    {
        $service = app(BackupService::class);
        $backup = $service->create('MANUAL', $this->superAdmin);

        try {
            $service->restore($backup, $this->superAdmin, 'pulihkan');
            $this->fail('Konfirmasi harus persis PULIHKAN.');
        } catch (VotingException) {
            $this->assertTrue(true);
        }

        $election = Election::factory()->create();
        Candidate::factory()->for(Ballot::factory()->for($election))->create(['number' => 1]);
        app(ElectionLifecycle::class)->markReady($election, $this->superAdmin);
        app(ElectionLifecycle::class)->start($election, $this->superAdmin);

        try {
            $service->restore($backup, $this->superAdmin, 'PULIHKAN');
            $this->fail('Restore saat pemilihan berlangsung harus ditolak.');
        } catch (VotingException $exception) {
            $this->assertStringContainsString('berlangsung', $exception->getMessage());
        }

        $adminRt = User::factory()->create();
        $adminRt->assignRole(User::ROLE_ADMIN_RT);

        $this->expectException(HttpException::class);
        $service->restore($backup, $adminRt, 'PULIHKAN');
    }

    public function test_download_requires_signed_link_for_the_same_super_admin(): void
    {
        $backup = app(BackupService::class)->create('MANUAL', $this->superAdmin);
        $signed = URL::temporarySignedRoute('backups.download', now()->addMinutes(2), ['backup' => $backup->public_id, 'user' => $this->superAdmin->id]);

        $this->actingAs($this->superAdmin)->get(route('backups.download', ['backup' => $backup->public_id, 'user' => $this->superAdmin->id]))->assertForbidden();
        $this->actingAs($this->superAdmin)->get($signed)->assertOk()->assertDownload($backup->filename);

        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole(User::ROLE_SUPER_ADMIN);
        $this->actingAs($otherAdmin)->get($signed)->assertForbidden();
    }

    public function test_backup_page_is_super_admin_only(): void
    {
        $this->actingAs($this->superAdmin)->get(BackupPage::getUrl())->assertOk()->assertSee('Backup Sekarang');

        $adminRt = User::factory()->create();
        $adminRt->forceFill(['has_email_authentication' => true])->save();
        $adminRt->assignRole(User::ROLE_ADMIN_RT);
        $this->actingAs($adminRt)->get(BackupPage::getUrl())->assertForbidden();
    }
}
