<?php

namespace Tests\Feature;

use App\Enums\BallotScope;
use App\Enums\DeviceKind;
use App\Enums\ElectionMode;
use App\Enums\EmergencyAddReason;
use App\Enums\PermitStatus;
use App\Filament\Pages\DeskPage;
use App\Filament\Pages\DevicesPage;
use App\Filament\Pages\MonitoringPage;
use App\Filament\Pages\ParticipationPage;
use App\Filament\Support\ElectionChecklist;
use App\Models\AuditLog;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Device;
use App\Models\Election;
use App\Models\Permit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Voter;
use App\Services\Devices\DeviceManager;
use App\Services\Voters\VoterRegistry;
use App\Services\Voting\ElectionLifecycle;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\TestCase;

class DeskPageTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $deskOfficer;

    private Election $election;

    private Voter $voter;

    private string $deskCookie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');

        $rt03 = Unit::query()->where('code', '03')->firstOrFail();
        $this->superAdmin = $this->makeUser(User::ROLE_SUPER_ADMIN);
        $this->deskOfficer = $this->makeUser(User::ROLE_ADMIN_RT, $rt03, [User::PERMISSION_DESK]);

        $this->election = Election::factory()->create(['mode' => ElectionMode::Resmi, 'settings' => ['max_booths_per_unit' => 1]]);
        $ballot = Ballot::factory()->for($this->election)->create(['title' => 'Ketua RW', 'scope' => BallotScope::SemuaRt]);
        Candidate::factory()->for($ballot)->create(['number' => 1]);
        $this->voter = Voter::factory()->for($rt03)->create(['name' => 'Budi Santoso']);

        $lifecycle = app(ElectionLifecycle::class);
        $lifecycle->markReady($this->election, $this->superAdmin);

        $manager = app(DeviceManager::class);
        $desk = Device::query()->where('kind', DeviceKind::Meja)->where('unit_id', $rt03->id)->firstOrFail();
        $pairedDesk = $manager->pair($manager->issueToken($desk, $this->superAdmin), 'Laptop Meja', Request::create('/'), DeviceKind::Meja);
        $this->deskCookie = $desk->public_id.'|'.$pairedDesk['secret'];

        $booth = Device::query()->where('kind', DeviceKind::Bilik)->where('unit_id', $rt03->id)->firstOrFail();
        $manager->pair($manager->issueToken($booth, $this->superAdmin), 'Laptop Bilik', Request::create('/'), DeviceKind::Bilik);

        $lifecycle->start($this->election, $this->superAdmin);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function makeUser(string $role, ?Unit $unit = null, array $permissions = []): User
    {
        $user = User::factory()->create();
        $user->forceFill(['has_email_authentication' => true, 'unit_id' => $unit?->id])->save();
        $user->assignRole($role);
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_desk_officer_on_desk_laptop_grants_permit(): void
    {
        $this->actingAs($this->deskOfficer);

        Livewire::withCookies([DeviceManager::COOKIE => $this->deskCookie])
            ->test(DeskPage::class)
            ->set('search', 'budi')
            ->assertSee('Budi Santoso')
            ->assertSee('Laptop Meja')
            ->callAction(TestAction::make('grant')->arguments(['voter' => $this->voter->public_id]))
            ->assertSet('lastAssignment.booth', 'Bilik 1');

        $this->assertSame(1, Permit::query()->whereNotNull('active_voter_id')->count());
    }

    public function test_desk_lists_all_rt_voters_without_typing_with_counts_per_tab(): void
    {
        $this->actingAs($this->deskOfficer);
        $rt03 = Unit::query()->where('code', '03')->firstOrFail();
        Voter::factory()->for($rt03)->create(['name' => 'Ani Lestari']);
        Voter::factory()->for(Unit::query()->where('code', '04')->firstOrFail())->create(['name' => 'Warga RT Empat']);

        // Pemilih yang ditambah setelah Mulai tidak masuk daftar beku: tampil di Semua sebagai tidak berhak.
        Livewire::withCookies([DeviceManager::COOKIE => $this->deskCookie])
            ->test(DeskPage::class)
            ->assertSee('Budi Santoso')
            ->assertSee('No. absen 1')
            ->assertSee('Belum memilih (1)')
            ->assertSee('Sudah memilih (0)')
            ->assertSee('Semua (2)')
            ->assertDontSee('Warga RT Empat')
            ->set('voterTab', 'semua')
            ->assertSee('Ani Lestari')
            ->set('search', 'ani')
            ->assertDontSee('Budi Santoso');

        // Menu Partisipasi tidak perlu untuk petugas meja: isinya sudah di Meja Izin.
        $this->assertFalse(ParticipationPage::shouldRegisterNavigation());
    }

    public function test_unregistered_resident_can_be_added_at_the_desk_and_allowed_to_vote(): void
    {
        // Petugas meja tanpa izin kelola pemilih: tombolnya tidak ada.
        $this->actingAs($this->deskOfficer);
        Livewire::withCookies([DeviceManager::COOKIE => $this->deskCookie])
            ->test(DeskPage::class)
            ->assertActionHidden('emergencyAdd');

        $this->deskOfficer->givePermissionTo(User::PERMISSION_MANAGE_VOTERS);

        $page = Livewire::withCookies([DeviceManager::COOKIE => $this->deskCookie])
            ->test(DeskPage::class)
            ->callAction('emergencyAdd', ['name' => 'Warga Baru Datang', 'reason' => EmergencyAddReason::BelumAdaDiDataAwal->value])
            ->assertNotified('Warga Baru Datang ditambahkan. Silakan izinkan memilih.')
            ->assertSee('Warga Baru Datang');

        $voter = Voter::query()->where('name', 'Warga Baru Datang')->sole();
        $this->assertTrue($voter->added_during_live);
        $this->assertSame($this->deskOfficer->unit_id, $voter->unit_id);

        $page->callAction(TestAction::make('grant')->arguments(['voter' => $voter->public_id]))
            ->assertSet('lastAssignment.booth', 'Bilik 1');
    }

    public function test_each_rt_adds_and_removes_its_own_booths(): void
    {
        $this->actingAs($this->deskOfficer);
        $rt03 = $this->deskOfficer->unit_id;
        $booths = fn (): int => Device::query()->where('unit_id', $rt03)->where('kind', DeviceKind::Bilik)->count();

        $page = Livewire::withCookies([DeviceManager::COOKIE => $this->deskCookie])
            ->test(DeskPage::class)
            ->callAction('addBooth')
            ->assertNotified('Bilik 2 ditambahkan. Klik Buat Token untuk memasang laptopnya.')
            ->assertSee('Bilik 2');
        $this->assertSame(2, $booths());

        // Bilik baru yang belum dipakai bisa dihapus; bilik 1 (terpasang) tidak.
        $new = Device::query()->where('unit_id', $rt03)->where('kind', DeviceKind::Bilik)->where('number', 2)->sole();
        $old = Device::query()->where('unit_id', $rt03)->where('kind', DeviceKind::Bilik)->where('number', 1)->sole();
        $page->assertActionHidden(TestAction::make('removeBooth')->arguments(['booth' => $old->public_id]))
            ->callAction(TestAction::make('removeBooth')->arguments(['booth' => $new->public_id]))
            ->assertNotified('Bilik dihapus.');
        $this->assertSame(1, $booths());

        // Bilik yang dihapus tidak dibuat ulang otomatis.
        app(DeviceManager::class)->ensureSlots($this->election->fresh());
        $this->assertSame(1, $booths());
        $this->assertTrue(AuditLog::query()->where('action', 'device.booth_removed')->exists());
    }

    public function test_booth_added_mid_voting_takes_the_next_voter_without_disturbing_others(): void
    {
        $this->actingAs($this->deskOfficer);
        $rt03 = Unit::query()->where('code', '03')->firstOrFail();
        // Pemilihan sudah berjalan: warga kedua masuk lewat tambah darurat (ikut daftar berhak).
        $second = app(VoterRegistry::class)->create(['name' => 'Siti Warga'], $rt03->id, $this->superAdmin, emergencyReason: EmergencyAddReason::BelumAdaDiDataAwal->value);
        Device::query()->update(['last_seen_at' => now()]);

        $page = Livewire::withCookies([DeviceManager::COOKIE => $this->deskCookie])->test(DeskPage::class)
            ->callAction(TestAction::make('grant')->arguments(['voter' => $this->voter->public_id]))
            ->assertSet('lastAssignment.booth', 'Bilik 1');

        // Satu-satunya bilik sedang dipakai: warga berikutnya harus menunggu.
        $page->callAction(TestAction::make('grant')->arguments(['voter' => $second->public_id]))
            ->assertNotified('Semua bilik terpakai. Tunggu bilik kosong.');

        // Di tengah acara: tambah bilik, pasang laptopnya dengan token dari meja.
        $page->callAction('addBooth')->assertNotified('Bilik 2 ditambahkan. Klik Buat Token untuk memasang laptopnya.');
        $booth2 = Device::query()->where('unit_id', $rt03->id)->where('kind', DeviceKind::Bilik)->where('number', 2)->sole();
        $manager = app(DeviceManager::class);
        $manager->pair($manager->issueToken($booth2, $this->deskOfficer), 'Laptop Bilik Tambahan', Request::create('/'), DeviceKind::Bilik);
        $booth2->fresh()->forceFill(['last_seen_at' => now()])->save();

        // Warga berikutnya langsung diarahkan ke bilik baru; izin di Bilik 1 tidak terganggu.
        $page->callAction(TestAction::make('grant')->arguments(['voter' => $second->public_id]))
            ->assertSet('lastAssignment.booth', 'Bilik 2');

        $this->assertSame(2, Permit::query()->whereNotNull('active_voter_id')->count());
        $this->assertSame('Bilik 1', Permit::query()->where('voter_id', $this->voter->id)->sole()->device->name());
    }

    public function test_go_to_booth_card_disappears_once_the_permit_ends(): void
    {
        $this->actingAs($this->deskOfficer);

        $page = Livewire::withCookies([DeviceManager::COOKIE => $this->deskCookie])
            ->test(DeskPage::class)
            ->callAction(TestAction::make('grant')->arguments(['voter' => $this->voter->public_id]))
            ->assertSee('Silakan ke Bilik 1');

        Permit::query()->update(['status' => PermitStatus::Selesai, 'active_voter_id' => null, 'active_device_id' => null]);

        $page->call('$refresh')->assertDontSee('Silakan ke Bilik 1')->assertSet('lastAssignment', null);
    }

    public function test_login_page_confirms_a_paired_desk_laptop(): void
    {
        $this->withCookie(DeviceManager::COOKIE, $this->deskCookie)
            ->get('/admin/login')
            ->assertOk()
            ->assertSee('Laptop ini terpasang sebagai Meja RT 03');

        // Laptop lain (tanpa cookie meja): tidak ada pesan.
        $this->defaultCookies = [];
        $this->get('/admin/login')->assertOk()->assertDontSee('Laptop ini terpasang sebagai');
    }

    public function test_same_officer_on_another_laptop_cannot_grant(): void
    {
        $this->actingAs($this->deskOfficer);

        Livewire::test(DeskPage::class)
            ->set('search', 'budi')
            ->assertSee('Bukan laptop Meja RT Anda')
            ->assertActionHidden(TestAction::make('grant')->arguments(['voter' => $this->voter->public_id]));

        $this->assertSame(0, Permit::query()->count());
    }

    public function test_checklist_names_rts_without_voters_or_desk_account(): void
    {
        $this->actingAs($this->superAdmin);
        $draft = Election::factory()->create(['mode' => ElectionMode::Resmi]);

        $steps = collect(ElectionChecklist::steps($draft))->keyBy('title');

        $this->assertStringContainsString('RT 03 1', $steps['Data pemilih']['detail']);
        $this->assertStringContainsString('Belum ada pemilih: RT 01', $steps['Data pemilih']['detail']);
        $this->assertStringContainsString('Belum ada akun Meja Izin: RT 01', $steps['Akun petugas meja (Admin RT)']['detail']);
        $this->assertStringNotContainsString('RT 03', $steps['Akun petugas meja (Admin RT)']['detail']);
    }

    public function test_monitoring_shows_every_rt_with_devices_and_participation(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(MonitoringPage::class)
            ->assertSee('Monitoring')
            ->assertSee('RT 03')
            ->assertSee('dari 1 pemilih sudah memilih')
            ->assertSee('1 dari 1 tersambung')
            ->assertSee('Tersambung');

        // Laptop bilik RT 03 lama tidak terdengar: RT itu ditandai perlu dicek.
        Device::query()->where('kind', DeviceKind::Bilik)->update(['last_seen_at' => now()->subMinutes(5)]);
        Livewire::test(MonitoringPage::class)
            ->assertSee('RT perlu dicek')
            ->assertSee('Bilik terputus: RT03-01');

        // Daftar nama (dulu menu Partisipasi) ada di Monitoring: klik kartu RT.
        Livewire::test(MonitoringPage::class)
            ->call('showUnit', $this->voter->unit_id)
            ->assertSee('Belum memilih (1 orang)')
            ->assertSee('Budi Santoso');
        $this->assertFalse(ParticipationPage::shouldRegisterNavigation(), 'Super Admin memakai Monitoring');

        $this->actingAs($this->deskOfficer)->get(MonitoringPage::getUrl())->assertForbidden();
    }

    public function test_pages_are_restricted_by_role(): void
    {
        $this->actingAs($this->deskOfficer)->get(DeskPage::getUrl())->assertOk();
        $this->actingAs($this->deskOfficer)->get(DevicesPage::getUrl())->assertForbidden();
        $this->actingAs($this->superAdmin)->get(DevicesPage::getUrl())->assertOk()->assertSee('RT03-MEJA');
        $this->actingAs($this->superAdmin)->get(DeskPage::getUrl())->assertForbidden();
    }
}
