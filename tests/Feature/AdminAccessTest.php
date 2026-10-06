<?php

namespace Tests\Feature;

use App\Enums\BallotScope;
use App\Enums\ElectionStatus;
use App\Enums\StaffRole;
use App\Enums\WaveKind;
use App\Filament\Pages\AttendanceList;
use App\Filament\Pages\ControlRoom;
use App\Filament\Pages\DoorDesk;
use App\Filament\Pages\ResultScreen;
use App\Filament\Resources\Elections\ElectionResource;
use App\Filament\Resources\Elections\Pages\EditElection;
use App\Filament\Resources\Elections\Pages\ListElections;
use App\Filament\Resources\Elections\RelationManagers\BallotsRelationManager;
use App\Filament\Resources\Elections\RelationManagers\HistoryRelationManager;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\ElectionStaff;
use App\Models\User;
use App\Services\Voting\AttendeeRegistrar;
use App\Services\Voting\ElectionLifecycle;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\Email\Notifications\VerifyEmailAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Election $election;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->superAdmin = $this->makeUser(User::ROLE_SUPER_ADMIN);
        $this->election = Election::factory()->create();
        Candidate::factory()->for(Ballot::factory()->for($this->election))->create(['number' => 1]);
        app(ElectionLifecycle::class)->markReady($this->election, $this->superAdmin);
    }

    private function makeUser(string $role, ?StaffRole $staffRole = null, ?Election $election = null): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'must_change_password' => false,
            'has_email_authentication' => true,
        ])->save();
        $user->assignRole($role);

        if ($staffRole !== null) {
            $staff = new ElectionStaff(['user_id' => $user->id, 'role' => $staffRole]);
            $staff->election()->associate($election ?? $this->election);
            $staff->save();
        }

        return $user;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get(route('screens.qr', $this->election->public_id))->assertRedirect('/admin/login');
    }

    public function test_user_without_mfa_is_forced_to_set_it_up(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['must_change_password' => false])->save();
        $user->assignRole(User::ROLE_SUPER_ADMIN);

        $this->actingAs($user)->get('/admin')->assertRedirectContains('multi-factor');
    }

    public function test_login_sends_two_factor_code_by_email_before_signing_in(): void
    {
        NotificationFacade::fake();
        $user = $this->makeUser(User::ROLE_SUPER_ADMIN);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate');

        NotificationFacade::assertSentTo($user, VerifyEmailAuthentication::class);
        $this->assertGuest();
    }

    public function test_user_must_change_temporary_password_first(): void
    {
        $user = $this->makeUser(User::ROLE_SUPER_ADMIN);
        $user->forceFill(['must_change_password' => true])->save();

        $this->actingAs($user)->get('/admin/elections')->assertRedirect('/admin/profile');
    }

    public function test_door_staff_cannot_open_election_settings_or_control_room(): void
    {
        $door = $this->makeUser(User::ROLE_STAFF, StaffRole::PetugasPintu);

        $this->actingAs($door)->get(DoorDesk::getUrl())->assertOk();
        $this->actingAs($door)->get(ControlRoom::getUrl())->assertForbidden();
        $this->actingAs($door)->get(ElectionResource::getUrl())->assertForbidden();
        $this->actingAs($door)->get(route('screens.qr', $this->election->public_id))->assertForbidden();
    }

    public function test_door_staff_registers_attendee_and_sees_pin_once(): void
    {
        $door = $this->makeUser(User::ROLE_STAFF, StaffRole::PetugasPintu);
        $this->actingAs($door);

        $component = Livewire::test(DoorDesk::class)
            ->fillForm(['name' => 'Budi Santoso'])
            ->call('register')
            ->assertSet('issued.name', 'Budi Santoso');

        $pin = $component->get('issued.pin');
        $this->assertMatchesRegularExpression('/^\d{4}$/', $pin);
        $this->assertSame(1, Attendee::query()->count());

        $component->call('acknowledge')->assertSet('issued', null);
    }

    public function test_duplicate_name_requires_confirmation(): void
    {
        $door = $this->makeUser(User::ROLE_STAFF, StaffRole::PetugasPintu);
        $this->actingAs($door);

        Livewire::test(DoorDesk::class)->fillForm(['name' => 'Siti Aminah'])->call('register')->call('acknowledge');

        $component = Livewire::test(DoorDesk::class)
            ->fillForm(['name' => 'siti  aminah'])
            ->call('register');

        $this->assertNotEmpty($component->get('similarNames'));
        $this->assertSame(1, Attendee::query()->count());

        $component->fillForm(['name' => 'siti aminah'])->call('register');
        $this->assertSame(2, Attendee::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'attendee.duplicate_name_confirmed')->exists());
    }

    public function test_staff_of_another_election_cannot_register_here(): void
    {
        $other = Election::factory()->create();
        $door = $this->makeUser(User::ROLE_STAFF, StaffRole::PetugasPintu, $other);
        $this->actingAs($door);

        Livewire::test(DoorDesk::class, ['electionId' => $this->election->public_id])
            ->fillForm(['name' => 'Penyusup'])
            ->call('register')
            ->assertForbidden();

        $this->assertSame(0, Attendee::query()->count());
    }

    public function test_super_admin_can_open_live_election_page_and_close_it_but_not_edit_config(): void
    {
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);
        $this->actingAs($this->superAdmin);

        $this->get(ElectionResource::getUrl('edit', ['record' => $this->election]))->assertOk()->assertSee('Status');

        Livewire::test(EditElection::class, ['record' => $this->election->public_id])
            ->assertActionHidden('settings')
            ->callAction('close', ['current_password' => 'password'])
            ->assertHasNoFormErrors();

        $this->assertSame(ElectionStatus::Ditutup, $this->election->fresh()->status);

        $committee = $this->makeUser(User::ROLE_STAFF, StaffRole::Panitia);
        $this->actingAs($committee)->get(ElectionResource::getUrl('edit', ['record' => $this->election]))->assertForbidden();
    }

    public function test_checklist_shows_progress_of_each_preparation_step(): void
    {
        $draft = Election::factory()->create(['name' => 'Penjaringan baru']);
        Candidate::factory()->for(Ballot::factory()->for($draft))->create(['number' => 1]);
        $this->actingAs($this->superAdmin);

        Livewire::test(EditElection::class, ['record' => $draft->public_id])
            ->assertSee('1 surat suara: Calon Ketua RW')
            ->assertSee('1 calon (1 belum berfoto)')
            ->assertSee('0 Panitia, 0 Petugas Pintu')
            ->assertSee('Lengkapi langkah di atas dulu');

        foreach ([StaffRole::Panitia, StaffRole::PetugasPintu] as $role) {
            $staff = new ElectionStaff(['user_id' => $this->superAdmin->id, 'role' => $role]);
            $staff->election()->associate($draft);
            $staff->save();
        }

        Livewire::test(EditElection::class, ['record' => $draft->public_id])
            ->assertSee('1 Panitia, 1 Petugas Pintu')
            ->assertSee('Semua langkah di atas lengkap. Tekan tombol Tandai Siap');
    }

    public function test_saving_with_empty_advanced_settings_keeps_defaults_and_other_settings(): void
    {
        $draft = Election::factory()->create(['name' => 'Draf lama', 'settings' => ['headcount' => 42, 'wave_minutes' => 7]]);
        $this->actingAs($this->superAdmin);

        Livewire::test(EditElection::class, ['record' => $draft->public_id])
            ->assertSee('Persiapan')
            ->assertSee('Belum ada. Tambah satu')
            ->callAction('settings', ['name' => 'Draf baru', 'settings.wave_minutes' => null])
            ->assertHasNoFormErrors();

        $draft->refresh();
        $this->assertSame('Draf baru', $draft->name);
        $this->assertSame(42, $draft->setting('headcount'), 'Pengaturan di luar form tidak boleh hilang');
        $this->assertSame(config('voting.defaults.wave_minutes'), $draft->setting('wave_minutes'), 'Kotak kosong memakai nilai bawaan');
    }

    public function test_election_list_hides_cancelled_and_keeps_manage_for_live(): void
    {
        $this->actingAs($this->superAdmin);
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);
        $cancelled = Election::factory()->create(['name' => 'Latihan batal']);
        app(ElectionLifecycle::class)->cancel($cancelled, $this->superAdmin, 'latihan');

        Livewire::test(ListElections::class)
            ->assertCanSeeTableRecords([$this->election])
            ->assertCanNotSeeTableRecords([$cancelled->fresh()])
            ->assertActionVisible(TestAction::make('edit')->table($this->election))
            ->filterTable('closed', true)
            ->assertCanSeeTableRecords([$this->election, $cancelled->fresh()]);
    }

    public function test_cancelled_election_history_and_attendance_remain_viewable(): void
    {
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);
        app(AttendeeRegistrar::class)->register($this->election, 'Budi Hadir', null, $this->superAdmin);
        app(ElectionLifecycle::class)->cancel($this->election, $this->superAdmin, 'Latihan diulang');
        $this->actingAs($this->superAdmin);

        Livewire::test(HistoryRelationManager::class, ['ownerRecord' => $this->election->fresh(), 'pageClass' => EditElection::class])
            ->assertSee('Status berubah: Berlangsung → Dibatalkan')
            ->assertSee('Latihan diulang')
            ->assertSee('Peserta didata di pintu');

        Livewire::test(AttendanceList::class, ['electionId' => $this->election->public_id])
            ->assertSee('Budi Hadir');
    }

    public function test_adding_dadakan_ballot_needs_only_a_title(): void
    {
        $draft = Election::factory()->create(['name' => 'Penjaringan baru']);
        $this->actingAs($this->superAdmin);

        Livewire::test(BallotsRelationManager::class, ['ownerRecord' => $draft, 'pageClass' => EditElection::class])
            ->callAction(TestAction::make('create')->table(), ['title' => 'Calon Ketua RW'])
            ->assertHasNoFormErrors();

        $ballot = $draft->ballots()->firstOrFail();
        $this->assertSame('Calon Ketua RW', $ballot->title);
        $this->assertSame(BallotScope::DaftarHadir, $ballot->scope);
        $this->assertSame(1, $ballot->sort);
    }

    public function test_committee_opens_wave_from_control_room(): void
    {
        $committee = $this->makeUser(User::ROLE_STAFF, StaffRole::Panitia);
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);
        $this->actingAs($committee);

        Livewire::test(ControlRoom::class)
            ->callAction('openWave', ['kind' => WaveKind::Terbuka->value, 'minutes' => 5])
            ->assertHasNoActionErrors();

        $this->assertNotNull($this->election->fresh()->openWave());
        $this->actingAs($committee)->get(route('screens.qr', $this->election->public_id))->assertOk();
    }

    public function test_results_are_unavailable_until_closed_and_reveal_is_audited(): void
    {
        $committee = $this->makeUser(User::ROLE_STAFF, StaffRole::Panitia);
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);
        $this->actingAs($committee);

        Livewire::test(ResultScreen::class)->assertSee('Hasil hanya tersedia setelah pemilihan');

        app(ElectionLifecycle::class)->close($this->election, $this->superAdmin);

        Livewire::test(ResultScreen::class)
            ->call('reveal')
            ->assertSet('revealed', true)
            ->assertSee('Suara sah');

        $this->assertTrue(AuditLog::query()->where('action', 'results.revealed')->exists());
    }
}
