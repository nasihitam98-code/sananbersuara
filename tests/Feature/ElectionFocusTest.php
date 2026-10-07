<?php

namespace Tests\Feature;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\StaffRole;
use App\Filament\Pages\ControlRoom;
use App\Filament\Pages\DoorDesk;
use App\Filament\Resources\Candidates\Pages\ListCandidates;
use App\Filament\Resources\Elections\ElectionResource;
use App\Filament\Resources\Elections\Pages\ListElections;
use App\Filament\Support\Workspace;
use App\Filament\Widgets\HomeGuide;
use App\Models\AuditLog;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\ElectionStaff;
use App\Models\User;
use App\Services\Voting\ElectionLifecycle;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ElectionFocusTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->forceFill(['must_change_password' => false, 'has_email_authentication' => true])->save();
        $this->superAdmin->assignRole(User::ROLE_SUPER_ADMIN);
        $this->actingAs($this->superAdmin);
        session([Workspace::SESSION_KEY => ElectionMode::Dadakan->value]);
    }

    private function election(string $name, ?ElectionStatus $status = null): Election
    {
        $election = Election::factory()->create(['name' => $name]);
        $ballot = Ballot::factory()->for($election)->create();
        Candidate::factory()->for($ballot)->create(['number' => 1, 'name' => "Calon {$name}"]);
        Candidate::factory()->for($ballot)->create(['number' => 2, 'name' => "Calon kedua {$name}"]);

        if ($status !== null && $status !== ElectionStatus::Draft) {
            $lifecycle = app(ElectionLifecycle::class);
            $lifecycle->markReady($election, $this->superAdmin);

            if (in_array($status, [ElectionStatus::Berlangsung, ElectionStatus::Ditutup], true)) {
                $lifecycle->start($election->fresh(), $this->superAdmin);
            }

            if ($status === ElectionStatus::Ditutup) {
                $lifecycle->close($election->fresh(), $this->superAdmin);
            }
        }

        return $election->fresh();
    }

    public function test_pages_follow_the_chosen_election_instead_of_the_running_one(): void
    {
        $running = $this->election('Sedang Jalan', ElectionStatus::Berlangsung);
        $prepared = $this->election('Disiapkan', ElectionStatus::Ready);

        $this->get(route('workspace.election', $prepared->public_id))->assertRedirect(url('/admin'));
        $this->assertTrue(Workspace::election()->is($prepared));

        Livewire::test(ControlRoom::class)
            ->assertSet('electionId', $prepared->public_id)
            ->assertDontSee('Sedang Jalan · Berlangsung');

        $this->get('/admin')
            ->assertSee('Pemilihan lain sedang berlangsung')
            ->assertSee(route('workspace.election', $running->public_id), false);
    }

    public function test_chooser_asks_for_confirmation_when_another_election_is_running(): void
    {
        $this->election('Sedang Jalan', ElectionStatus::Berlangsung);
        $this->election('Disiapkan', ElectionStatus::Ready);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Pemilihan mana yang mau dikerjakan?')
            ->assertSee('Sedang berlangsung: Sedang Jalan')
            ->assertSee('Tetap masuk ke', false);
    }

    public function test_draft_election_can_be_deleted_from_its_card(): void
    {
        $draft = $this->election('Coba-coba');

        Livewire::test(HomeGuide::class)
            ->assertActionVisible(TestAction::make('deleteElection')->arguments(['election' => $draft->public_id]))
            ->assertActionHidden(TestAction::make('cancelElection')->arguments(['election' => $draft->public_id]))
            ->callAction(TestAction::make('deleteElection')->arguments(['election' => $draft->public_id]))
            ->assertNotified('Pemilihan "Coba-coba" dihapus.');

        $this->assertModelMissing($draft);
        $this->assertSame(0, Candidate::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'election.deleted')->exists());
    }

    public function test_started_election_is_cancelled_not_deleted_and_disappears_from_the_list(): void
    {
        $closed = $this->election('Latihan Lama', ElectionStatus::Ditutup);

        Livewire::test(HomeGuide::class)
            ->assertActionHidden(TestAction::make('deleteElection')->arguments(['election' => $closed->public_id]))
            ->callAction(TestAction::make('cancelElection')->arguments(['election' => $closed->public_id]), ['note' => 'Data latihan', 'current_password' => 'password']);

        $this->assertSame(ElectionStatus::Cancelled, $closed->fresh()->status);
        $this->assertFalse(Workspace::elections()->contains(fn (Election $election): bool => $election->is($closed)));

        // Tetap bisa dilihat dari Arsip di Beranda: riwayat dan daftar hadir.
        $current = $this->election('Sekarang');
        $this->get(route('workspace.election', $current->public_id));
        $this->get('/admin')
            ->assertSee('Arsip: pemilihan dibatalkan (1)')
            ->assertSee(ControlRoom::getUrl(['pemilihan' => $closed->public_id]), false);

        Livewire::withQueryParams(['pemilihan' => $closed->public_id])
            ->test(ControlRoom::class)
            ->assertSee('Menampilkan:')
            ->assertSee('Latihan Lama');
    }

    public function test_committee_with_one_election_enters_it_and_cannot_choose_others(): void
    {
        $draft = $this->election('Draf Panitia');
        $committee = User::factory()->create();
        $committee->forceFill(['must_change_password' => false, 'has_email_authentication' => true])->save();
        $committee->assignRole(User::ROLE_STAFF);
        $staff = new ElectionStaff(['user_id' => $committee->id, 'role' => StaffRole::Panitia]);
        $staff->election()->associate($draft);
        $staff->save();
        $this->actingAs($committee);

        $this->assertTrue(Workspace::election()?->is($draft), 'Staf dengan satu pemilihan langsung masuk ke pemilihan itu');
        $this->get(route('workspace.election', $this->election('Bukan Tugasnya')->public_id))->assertForbidden();
    }

    public function test_start_from_card_readies_starts_and_opens_voting_in_one_step(): void
    {
        $draft = $this->election('Langsung Mulai');

        Livewire::test(HomeGuide::class)
            ->assertActionVisible(TestAction::make('startElection')->arguments(['election' => $draft->public_id]))
            ->callAction(TestAction::make('startElection')->arguments(['election' => $draft->public_id]), ['current_password' => 'password', 'open_now' => true, 'minutes' => 5])
            ->assertNotified('Pemilihan dimulai dan voting dibuka.');

        $draft->refresh();
        $this->assertSame(ElectionStatus::Berlangsung, $draft->status);
        $this->assertNotNull($draft->openWave());
        $this->assertTrue(Workspace::election()->is($draft), 'Pemilihan yang dimulai menjadi pemilihan yang dikerjakan');
    }

    public function test_start_from_card_explains_what_is_missing(): void
    {
        $empty = Election::factory()->create(['name' => 'Belum Ada Calon']);

        Livewire::test(HomeGuide::class)
            ->callAction(TestAction::make('startElection')->arguments(['election' => $empty->public_id]), ['current_password' => 'password'])
            ->assertNotified('Belum bisa dimulai');

        $this->assertSame(ElectionStatus::Draft, $empty->fresh()->status);
    }

    public function test_election_menus_appear_only_after_choosing_an_election(): void
    {
        $election = $this->election('Penjaringan');
        $this->election('Lainnya');

        $this->get('/admin')->assertDontSee(ControlRoom::getUrl(), false)->assertDontSee(DoorDesk::getUrl(), false);

        $this->get(route('workspace.election', $election->public_id));
        $this->get('/admin')->assertSee(ControlRoom::getUrl(), false)->assertSee(DoorDesk::getUrl(), false);
    }

    public function test_election_list_has_start_enter_delete_and_cancel_on_each_row(): void
    {
        $draft = $this->election('Draf Baru');
        $closed = $this->election('Sudah Ditutup', ElectionStatus::Ditutup);

        Livewire::test(ListElections::class)
            ->assertActionVisible(TestAction::make('start')->table($draft))
            ->assertActionVisible(TestAction::make('delete')->table($draft))
            ->assertActionHidden(TestAction::make('cancel')->table($draft))
            ->assertActionHidden(TestAction::make('start')->table($closed))
            ->assertActionHidden(TestAction::make('delete')->table($closed))
            ->assertActionVisible(TestAction::make('cancel')->table($closed))
            ->assertActionVisible(TestAction::make('enter')->table($closed))
            ->callAction(TestAction::make('start')->table($draft), ['current_password' => 'password', 'open_now' => false]);

        $this->assertSame(ElectionStatus::Berlangsung, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->openWave());
    }

    public function test_dadakan_sidebar_is_short_and_grouped_by_the_chosen_election(): void
    {
        $election = $this->election('Penjaringan');
        $this->get(route('workspace.election', $election->public_id));

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Pemilihan ini')
            ->assertSee('Pengaturan pemilihan')
            ->assertSee(ElectionResource::getUrl('edit', ['record' => $election]), false)
            ->assertSee('Lainnya')
            ->assertSee('Detail Suara')
            ->assertDontSee('Data dasar');

        $this->get(ElectionResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Nama pemilihan')
            ->assertDontSee('Pengaturan lanjutan');
    }

    public function test_candidate_list_shows_the_chosen_election(): void
    {
        $older = $this->election('Pertama');
        $this->election('Kedua');
        session([Workspace::ELECTION_SESSION_KEY => $older->public_id]);

        Livewire::test(ListCandidates::class)
            ->assertSee('Calon Pertama')
            ->assertDontSee('Calon Kedua');
    }
}
