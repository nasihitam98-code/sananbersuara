<?php

namespace Tests\Feature;

use App\Enums\BallotScope;
use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\StaffRole;
use App\Enums\WaveKind;
use App\Filament\Pages\ControlRoom;
use App\Filament\Pages\DoorDesk;
use App\Filament\Pages\ResultScreen;
use App\Filament\Resources\Elections\ElectionResource;
use App\Filament\Resources\Elections\Pages\EditElection;
use App\Filament\Resources\Elections\Pages\ListElections;
use App\Filament\Resources\Elections\RelationManagers\BallotsRelationManager;
use App\Filament\Resources\Elections\RelationManagers\HistoryRelationManager;
use App\Filament\Resources\Elections\RelationManagers\StaffRelationManager;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\ElectionStaff;
use App\Models\User;
use App\Services\Voting\AttendeeRegistrar;
use App\Services\Voting\BallotBox;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\VotingException;
use App\Services\Voting\WaveManager;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\Email\Notifications\VerifyEmailAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    public function test_user_without_mfa_is_not_asked_to_set_it_up(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['must_change_password' => false])->save();
        $user->assignRole(User::ROLE_SUPER_ADMIN);

        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_login_signs_in_with_password_only_without_email_code(): void
    {
        NotificationFacade::fake();
        $user = $this->makeUser(User::ROLE_SUPER_ADMIN);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate');

        NotificationFacade::assertNotSentTo($user, VerifyEmailAuthentication::class);
        $this->assertAuthenticatedAs($user);
    }

    public function test_forgotten_password_can_be_reset_from_server_console(): void
    {
        $this->artisan('pemilihan:reset-password', ['email' => strtoupper($this->superAdmin->email)])
            ->expectsOutputToContain('Password sementara (tampil sekali)')
            ->assertSuccessful();

        $this->superAdmin->refresh();
        $this->assertFalse(Hash::check('password', $this->superAdmin->password), 'Password lama tidak berlaku lagi');
        $this->assertTrue($this->superAdmin->must_change_password);
        $this->assertTrue(AuditLog::query()->where('action', 'user.password_reset_by_console')->exists());

        // Email salah: daftar akun yang ada ditampilkan agar pemilik server bisa memilih email yang benar.
        $this->artisan('pemilihan:reset-password', ['email' => 'tidak-ada@example.test'])
            ->expectsOutputToContain('Akun yang ada:')
            ->expectsOutputToContain($this->superAdmin->email)
            ->assertFailed();
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

        // Nama diketik huruf kecil semua → dirapikan; kartu PIN langsung dicetak (x-init), tombolnya untuk cetak ulang.
        $component = Livewire::test(DoorDesk::class)
            ->fillForm(['name' => '  budi   santoso '])
            ->call('register')
            ->assertSet('issued.name', 'Budi Santoso')
            ->assertSee('Cetak ulang kartu PIN')
            ->assertSeeHtml('autoMode: true')
            ->assertSeeHtml('x-init="if (forcePrint || (autoMode && auto)) { $nextTick(() => print()); }"');

        $pin = $component->get('issued.pin');
        $this->assertMatchesRegularExpression('/^\d{4}$/', $pin);
        $this->assertSame(1, Attendee::query()->count());
        $this->assertSame('Budi Santoso', Attendee::query()->value('name'));
        $component->assertSeeHtml('<div class="pin">'.$pin.'</div>')->assertSeeHtml('<div class="name">Budi Santoso</div>');

        $component->call('acknowledge')->assertSet('issued', null)->assertDontSee('Cetak ulang kartu PIN');
    }

    public function test_door_desk_and_control_room_share_a_table_with_pins_and_reprint(): void
    {
        $door = $this->makeUser(User::ROLE_STAFF, StaffRole::PetugasPintu);
        $otherDoor = $this->makeUser(User::ROLE_STAFF, StaffRole::PetugasPintu);
        $registrar = app(AttendeeRegistrar::class);
        ['attendee' => $own, 'pin' => $ownPin] = $registrar->register($this->election, 'Ojan', null, $door);
        ['attendee' => $others, 'pin' => $othersPin] = $registrar->register($this->election, 'Santi', null, $otherDoor);

        // PIN tersimpan terenkripsi (bukan teks biasa di database) dan tampil di tabel kedua halaman.
        $this->assertNotSame($ownPin, DB::table('attendees')->where('id', $own->id)->value('pin_encrypted'));
        $this->actingAs($door);
        Livewire::test(DoorDesk::class)
            ->assertSet('participantTab', 'semua')
            ->assertCanSeeTableRecords([$own, $others])
            ->assertSee($ownPin)
            ->assertSee($othersPin)
            // PIN tersimpan: petugas mana pun bisa mencetak ulang PIN yang sama; PIN baru/Pulihkan khusus Panitia.
            ->assertTableActionVisible('reprint', $others)
            ->assertTableActionHidden('newPin', $own)
            ->callTableAction('reprint', $others)
            ->assertSet('printCard.pin', $othersPin)
            ->assertSet('printCard.note', null);
        $this->assertSame($othersPin, $others->fresh()->storedPin(), 'Cetak ulang tidak mengganti PIN');

        $this->actingAs($this->superAdmin);
        Livewire::test(ControlRoom::class)->assertSee($ownPin)->assertTableActionVisible('reprint', $own);

        // PIN tidak tersimpan (didata sebelum fitur ini): Petugas Pintu hanya untuk orang yang ia daftarkan; jadi PIN baru.
        Attendee::query()->update(['pin_encrypted' => null]);
        $this->actingAs($door);
        Livewire::test(DoorDesk::class)
            ->assertTableActionHidden('reprint', $others)
            ->callTableAction('reprint', $own)
            ->assertSet('printCard.note', 'PIN BARU. PIN lama tidak berlaku.');
        $this->assertNotNull($own->fresh()->storedPin());
        $this->assertNotSame($ownPin, $own->fresh()->storedPin());

        // Ditutup: PIN tetap ada (putaran berikutnya untuk hasil seri memakai PIN yang sama). Dibatalkan/diumumkan: dihapus.
        app(ElectionLifecycle::class)->start($this->election->fresh(), $this->superAdmin);
        app(ElectionLifecycle::class)->close($this->election->fresh(), $this->superAdmin);
        $this->assertNotNull($own->fresh()->storedPin());
        app(ElectionLifecycle::class)->cancel($this->election->fresh(), $this->superAdmin, 'Gladi selesai');
        $this->assertSame(0, Attendee::query()->whereNotNull('pin_encrypted')->count());
    }

    public function test_attendee_can_be_deleted_with_confirmation_only_before_voting(): void
    {
        $door = $this->makeUser(User::ROLE_STAFF, StaffRole::PetugasPintu);
        $otherDoor = $this->makeUser(User::ROLE_STAFF, StaffRole::PetugasPintu);
        $registrar = app(AttendeeRegistrar::class);
        ['attendee' => $typo] = $registrar->register($this->election, 'Ojann', null, $door);
        ['attendee' => $others] = $registrar->register($this->election, 'Santi', null, $otherDoor);
        ['attendee' => $voter, 'pin' => $voterPin] = $registrar->register($this->election, 'Budi', null, $door);

        // Petugas Pintu: hanya orang yang ia daftarkan; alasan wajib.
        $this->actingAs($door);
        Livewire::test(DoorDesk::class)
            ->assertTableActionVisible('deleteAttendee', $typo)
            ->assertTableActionHidden('deleteAttendee', $others)
            ->callTableAction('deleteAttendee', $typo, ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        Livewire::test(DoorDesk::class)
            ->callTableAction('deleteAttendee', $typo, ['reason' => 'Salah ketik nama'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Ojann dihapus dari daftar hadir.');

        $this->assertModelMissing($typo);
        $this->assertTrue(AuditLog::query()->where('action', 'attendee.deleted')->where('note', 'Salah ketik nama')->exists());

        // Yang sudah memilih tidak bisa dihapus.
        app(ElectionLifecycle::class)->start($this->election->fresh(), $this->superAdmin);
        app(WaveManager::class)->open($this->election->fresh(), WaveKind::Terbuka, 10, $this->superAdmin);
        $election = $this->election->fresh();
        $ballot = $election->ballots()->firstOrFail();
        $box = app(BallotBox::class);
        $box->cast($election, $voter->fresh(), $box->verifyPin($election, $voter->fresh(), $voterPin), $ballot, $ballot->candidates()->firstOrFail());

        $this->actingAs($this->superAdmin);
        Livewire::test(ControlRoom::class)
            ->set('participantTab', 'semua')
            ->assertTableActionVisible('deleteAttendee', $others)
            ->assertTableActionHidden('deleteAttendee', $voter);

        $this->expectException(VotingException::class);
        $registrar->delete($election, $voter->fresh(), $this->superAdmin, 'Coba hapus');
    }

    public function test_control_room_lists_each_session_with_how_many_voted(): void
    {
        ['attendee' => $attendee, 'pin' => $pin] = app(AttendeeRegistrar::class)->register($this->election, 'Santi', null, $this->superAdmin);
        app(AttendeeRegistrar::class)->register($this->election, 'Ojan', null, $this->superAdmin);
        app(ElectionLifecycle::class)->start($this->election->fresh(), $this->superAdmin);
        app(WaveManager::class)->open($this->election->fresh(), WaveKind::Terbuka, 10, $this->superAdmin, 'Sesi utama');

        $election = $this->election->fresh();
        $ballot = $election->ballots()->firstOrFail();
        $box = app(BallotBox::class);
        $box->cast($election, $attendee->fresh(), $box->verifyPin($election, $attendee->fresh(), $pin), $ballot, $ballot->candidates()->firstOrFail());
        app(WaveManager::class)->close($election, $this->superAdmin);
        app(WaveManager::class)->open($election->fresh(), WaveKind::Terbuka, null, $this->superAdmin);

        $this->actingAs($this->superAdmin);
        $sessions = Livewire::test(ControlRoom::class)
            ->assertSee('Riwayat sesi')
            ->assertSee('Sesi utama')
            ->assertSee('masih dibuka')
            ->instance()
            ->sessionHistory($election);

        $this->assertSame(['Sesi utama', 'Sesi 2'], $sessions->pluck('label')->all());
        $this->assertSame([1, 0], $sessions->pluck('voters')->all());
        $this->assertSame(['10 menit', 'Tanpa timer'], $sessions->pluck('duration')->all());
    }

    public function test_panel_pages_refresh_themselves_except_pages_with_their_own_polling(): void
    {
        $this->actingAs($this->superAdmin)->get('/admin')
            ->assertOk()
            ->assertSee('window.Livewire.all()', false)
            ->assertSee('.fi-modal-open, .filepond--item', false);

        $this->assertStringContainsString('data-auto-refresh="off"', file_get_contents(resource_path('views/filament/pages/control-room.blade.php')));
    }

    public function test_door_desk_keeps_mixed_case_names_as_typed(): void
    {
        $this->assertSame('H. Abdul McLean', AttendeeRegistrar::tidyName('H. Abdul McLean'));
        $this->assertSame('Siti Aminah', AttendeeRegistrar::tidyName('SITI AMINAH'));
        $this->assertSame('Ni Luh Ayu', AttendeeRegistrar::tidyName("ni  luh\tayu"));
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
            // Panitia opsional: Super Admin bisa menjalankan semuanya sendiri, jadi tidak menghalangi Tandai Siap.
            ->assertSee('0 Panitia, 0 Petugas Pintu')
            ->assertSee('Semua langkah di atas lengkap. Tekan tombol Tandai Siap');

        foreach ([StaffRole::Panitia, StaffRole::PetugasPintu] as $role) {
            // Lewat tab Panitia & Petugas Pintu: daftar periksa diberi tahu agar langsung diperbarui.
            Livewire::test(StaffRelationManager::class, ['ownerRecord' => $draft, 'pageClass' => EditElection::class])
                ->callAction(TestAction::make('create')->table(), ['user_id' => $this->superAdmin->id, 'role' => $role->value])
                ->assertHasNoFormErrors()
                ->assertDispatched(EditElection::PREPARATION_UPDATED);
        }

        Livewire::test(EditElection::class, ['record' => $draft->public_id])
            ->assertSee('1 Panitia, 1 Petugas Pintu');
    }

    public function test_staff_role_can_be_changed_without_revoking(): void
    {
        $draft = Election::factory()->create();
        $this->actingAs($this->superAdmin);
        $staff = $draft->staff()->create(['user_id' => $this->superAdmin->id, 'role' => StaffRole::PetugasPintu]);

        Livewire::test(StaffRelationManager::class, ['ownerRecord' => $draft, 'pageClass' => EditElection::class])
            ->callAction(TestAction::make('edit')->table($staff), ['role' => StaffRole::Panitia->value])
            ->assertHasNoFormErrors()
            ->assertDispatched(EditElection::PREPARATION_UPDATED);

        $this->assertSame(StaffRole::Panitia, $staff->fresh()->role);
        $this->assertTrue(AuditLog::query()->where('action', 'election.staff_role_changed')->exists());

        // Peran yang sama untuk akun yang sama tidak boleh dobel.
        $draft->staff()->create(['user_id' => $this->superAdmin->id, 'role' => StaffRole::PetugasPintu]);

        Livewire::test(StaffRelationManager::class, ['ownerRecord' => $draft, 'pageClass' => EditElection::class])
            ->callAction(TestAction::make('edit')->table($staff), ['role' => StaffRole::PetugasPintu->value])
            ->assertHasFormErrors(['role' => 'unique']);
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
            ->assertActionVisible(TestAction::make('enter')->table($this->election))
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

        Livewire::test(ControlRoom::class, ['electionId' => $this->election->public_id])
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
            ->callAction('openWave', ['timed' => true, 'minutes' => 5])
            ->assertHasNoActionErrors();

        $this->assertNotNull($this->election->fresh()->openWave());
        $this->actingAs($committee)->get(route('screens.qr', $this->election->public_id))->assertOk();
    }

    public function test_committee_opens_named_wave_without_timer_and_can_add_timer_later(): void
    {
        $committee = $this->makeUser(User::ROLE_STAFF, StaffRole::Panitia);
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);
        $this->actingAs($committee);

        $component = Livewire::test(ControlRoom::class)
            ->callAction('openWave', ['name' => '  Sesi   pertama ', 'timed' => false])
            ->assertHasNoFormErrors()
            ->assertSee('Sesi pertama')
            ->assertSee('Tanpa timer')
            ->assertSee('Pasang timer');

        $wave = $this->election->fresh()->openWave();
        $this->assertSame('Sesi pertama', $wave->name);
        $this->assertNull($wave->ends_at);
        $this->assertTrue($wave->isOpenAt(now()->addHours(3)));

        $component->callAction('extendWave', ['minutes' => 5])->assertHasNoFormErrors();
        $this->assertTrue($this->election->fresh()->openWave()->ends_at->between(now()->addMinutes(4), now()->addMinutes(6)));

        app(AttendeeRegistrar::class)->register($this->election, 'Mbah Karto', null, $this->superAdmin);
        $component->callAction('closeWave')
            ->assertSee('1 orang belum memilih. Tekan BUKA VOTING untuk sesi berikutnya');
        $this->assertNull($this->election->fresh()->openWave());

        $component->callAction('openWave', ['name' => 'Sesi lansia'])->assertHasNoFormErrors();
        $next = $this->election->fresh()->openWave();
        $this->assertSame(2, $next->number);
        $this->assertSame(WaveKind::Terbuka, $next->kind);
        $this->assertNotNull($next->ends_at);
        $component->callAction('closeWave');

        $history = AuditLog::query()->where('action', 'wave.opened')->oldest('id')->firstOrFail();
        $this->assertSame('Voting dibuka: Sesi pertama', HistoryRelationManager::describe($history));
    }

    public function test_unnamed_wave_is_called_by_its_number(): void
    {
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);
        $wave = app(WaveManager::class)->open($this->election, WaveKind::Bantuan, null, $this->superAdmin, '   ');

        $this->assertNull($wave->name);
        $this->assertSame('Sesi 1', $wave->displayName());
    }

    public function test_super_admin_starts_election_from_control_room_but_committee_cannot(): void
    {
        $committee = $this->makeUser(User::ROLE_STAFF, StaffRole::Panitia);
        $this->actingAs($committee);

        Livewire::test(ControlRoom::class)
            ->assertActionHidden('startElection')
            ->assertActionHidden('openWave')
            ->assertSee('Tunggu Super Admin menekan');

        $this->actingAs($this->superAdmin);

        Livewire::test(ControlRoom::class)
            ->assertActionVisible('startElection')
            ->callAction('startElection', ['current_password' => 'password', 'open_now' => false])
            ->assertHasNoFormErrors()
            ->assertActionHidden('startElection')
            ->assertActionVisible('openWave')
            ->assertSee('BUKA VOTING')
            ->assertDontSee('Perpanjang')
            ->assertDontSee('Tutup Sekarang');

        $this->assertSame(ElectionStatus::Berlangsung, $this->election->fresh()->status);
    }

    public function test_control_room_lists_attendees_like_attendance_list(): void
    {
        $registrar = app(AttendeeRegistrar::class);
        $budi = $registrar->register($this->election, 'Budi Santoso', null, $this->superAdmin)['attendee'];
        $siti = $registrar->register($this->election, 'Siti Aminah', null, $this->superAdmin)['attendee'];
        $this->actingAs($this->makeUser(User::ROLE_STAFF, StaffRole::Panitia));

        Livewire::test(ControlRoom::class)
            ->assertCanSeeTableRecords([$budi, $siti])
            ->assertSee('Belum memilih')
            ->searchTable('siti')
            ->assertCanSeeTableRecords([$siti])
            ->assertCanNotSeeTableRecords([$budi])
            ->assertDontSee('Cari peserta (Pulihkan Hak Pilih)');
    }

    public function test_super_admin_closes_election_from_control_room_then_sees_result_screen_link(): void
    {
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);
        app(AttendeeRegistrar::class)->register($this->election, 'Mbah Karto', null, $this->superAdmin);

        $this->actingAs($this->makeUser(User::ROLE_STAFF, StaffRole::Panitia));
        Livewire::test(ControlRoom::class)->assertActionHidden('closeElection');

        $this->actingAs($this->superAdmin);
        Livewire::test(ControlRoom::class)
            ->assertActionVisible('closeElection')
            ->assertActionExists('closeElection', fn (Action $action): bool => str_contains((string) $action->getModalDescription(), 'Masih ada 1 orang yang belum memilih.'))
            ->callAction('closeElection', ['current_password' => 'password'])
            ->assertHasNoFormErrors()
            // Status berubah → halaman dimuat ulang agar lencana status dan menu ikut segar.
            ->assertRedirect();

        $this->assertSame(ElectionStatus::Ditutup, $this->election->fresh()->status);

        Livewire::test(ControlRoom::class)
            ->assertActionHidden('closeElection')
            ->assertSee('Buka Layar Hasil');
    }

    public function test_staff_tab_only_shows_for_dadakan_elections(): void
    {
        $this->actingAs($this->superAdmin);
        $resmi = Election::factory()->create(['mode' => ElectionMode::Resmi]);

        $this->assertTrue(StaffRelationManager::canViewForRecord($this->election, EditElection::class));
        $this->assertFalse(StaffRelationManager::canViewForRecord($resmi, EditElection::class));
    }

    public function test_restored_pin_can_be_printed_as_a_new_card(): void
    {
        $committee = $this->makeUser(User::ROLE_STAFF, StaffRole::Panitia);
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);
        $attendee = app(AttendeeRegistrar::class)->register($this->election, 'Budi Santoso', null, $this->superAdmin)['attendee'];
        $this->actingAs($committee);

        $component = Livewire::test(ControlRoom::class)
            ->callAction(TestAction::make('newPin')->table($attendee))
            ->assertHasNoFormErrors()
            ->assertSee('Cetak kartu PIN')
            ->assertSee('PIN BARU. PIN lama tidak berlaku.');

        $component->assertSeeHtml('<div class="pin">'.$component->get('reissued.pin').'</div>');

        $component->call('acknowledgeReissue')->assertDontSee('Cetak kartu PIN');
    }

    public function test_results_are_unavailable_until_closed_and_reveal_is_audited(): void
    {
        $committee = $this->makeUser(User::ROLE_STAFF, StaffRole::Panitia);
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);
        $this->actingAs($committee);

        Livewire::test(ResultScreen::class)->assertSee('Hasil hanya tersedia setelah pemilihan');

        app(ElectionLifecycle::class)->close($this->election, $this->superAdmin);

        $html = Livewire::test(ResultScreen::class)
            ->call('reveal')
            ->assertSet('revealed', true)
            ->assertSee('Suara sah')
            ->assertSee('Mulai pengumuman')
            ->assertSee('Buka di tab baru (proyektor)')
            ->assertSee('id="hasil-presentasi"', false)
            ->assertDontSee('Lanjut: Sahkan') // Verifikasi khusus Super Admin
            ->html();

        // Status pengumuman harus di elemen sendiri: atribut x-data kedua pada <section> Filament diabaikan browser.
        $this->assertMatchesRegularExpression('/<div\s+x-data="\{\s*total:/', $html);
        $this->assertDoesNotMatchRegularExpression('/<section[^>]*x-data="[^"]*"[^>]*x-data=/s', $html);

        $this->assertTrue(AuditLog::query()->where('action', 'results.revealed')->exists());
    }
}
