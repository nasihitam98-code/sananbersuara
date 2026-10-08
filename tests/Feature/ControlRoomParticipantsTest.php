<?php

namespace Tests\Feature;

use App\Enums\RestoreReason;
use App\Enums\StaffRole;
use App\Enums\VoteStatus;
use App\Enums\WaveKind;
use App\Filament\Pages\ControlRoom;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\ElectionStaff;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vote;
use App\Services\Exports\SpreadsheetSanitizer;
use App\Services\Voting\AttendeeRegistrar;
use App\Services\Voting\BallotBox;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\ResultsCalculator;
use App\Services\Voting\VoterRightRestorer;
use App\Services\Voting\VotingException;
use App\Services\Voting\WaveManager;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ControlRoomParticipantsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Election $election;

    private Ballot $ballot;

    private Candidate $candidate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->superAdmin = $this->makeUser(User::ROLE_SUPER_ADMIN);
        $this->election = Election::factory()->create();
        $this->ballot = Ballot::factory()->for($this->election)->create();
        $this->candidate = Candidate::factory()->for($this->ballot)->create(['number' => 1]);
        app(ElectionLifecycle::class)->markReady($this->election, $this->superAdmin);
    }

    private function makeUser(string $role, ?StaffRole $staffRole = null, ?Election $election = null): User
    {
        $user = User::factory()->create();
        $user->forceFill(['must_change_password' => false, 'has_email_authentication' => true])->save();
        $user->assignRole($role);

        if ($staffRole !== null) {
            $staff = new ElectionStaff(['user_id' => $user->id, 'role' => $staffRole]);
            $staff->election()->associate($election ?? $this->election);
            $staff->save();
        }

        return $user;
    }

    public function test_committee_sees_every_attendee_with_voted_status(): void
    {
        $registrar = app(AttendeeRegistrar::class);
        ['attendee' => $voted, 'pin' => $pin] = $registrar->register($this->election, 'Budi Santoso', null, $this->superAdmin);
        ['attendee' => $waiting] = $registrar->register($this->election, 'Mbah Karto', null, $this->superAdmin);
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);
        app(WaveManager::class)->open($this->election, WaveKind::Terbuka, 5, $this->superAdmin);
        $box = app(BallotBox::class);
        $box->cast($this->election, $voted, $box->verifyPin($this->election, $voted, $pin), $this->ballot, $this->candidate);

        $this->actingAs($this->makeUser(User::ROLE_STAFF, StaffRole::Panitia));

        Livewire::test(ControlRoom::class)
            ->assertSet('participantTab', 'belum')
            ->assertSee('Sudah memilih')
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$voted])
            ->call('setParticipantTab', 'sudah')
            ->assertCanSeeTableRecords([$voted])
            ->assertCanNotSeeTableRecords([$waiting])
            ->assertSee('✓ Sudah memilih')
            ->call('setParticipantTab', 'semua')
            ->assertCanSeeTableRecords([$voted, $waiting])
            ->searchTable('budi')
            ->assertCanSeeTableRecords([$voted])
            ->assertCanNotSeeTableRecords([$waiting])
            ->searchTable($waiting->displayNumber())
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$voted]);
    }

    public function test_participation_is_broken_down_per_rt_without_candidate_tallies(): void
    {
        [$rtOne, $rtTwo] = Unit::query()->orderBy('sort')->limit(2)->get()->all();
        $registrar = app(AttendeeRegistrar::class);
        ['attendee' => $voted, 'pin' => $pin] = $registrar->register($this->election, 'Budi Santoso', $rtOne->id, $this->superAdmin);
        $registrar->register($this->election, 'Mbah Karto', $rtOne->id, $this->superAdmin);
        $registrar->register($this->election, 'Siti Aminah', $rtTwo->id, $this->superAdmin);
        $registrar->register($this->election, 'Tamu Undangan', null, $this->superAdmin);
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);
        app(WaveManager::class)->open($this->election, WaveKind::Terbuka, 5, $this->superAdmin);
        $box = app(BallotBox::class);
        $box->cast($this->election, $voted, $box->verifyPin($this->election, $voted, $pin), $this->ballot, $this->candidate);

        $byUnit = collect(app(ResultsCalculator::class)->participationByUnit($this->election, $this->election->currentRound()))->keyBy('unit');

        $this->assertSame(['unit' => $rtOne->name, 'attendees' => 2, 'voted' => 1, 'not_voted' => 1, 'percent' => 50.0], $byUnit[$rtOne->name]);
        $this->assertSame(0, $byUnit[$rtTwo->name]['voted']);
        $this->assertSame(1, $byUnit['Tanpa RT']['attendees']);
        $this->assertSame('Tanpa RT', $byUnit->keys()->last());

        $this->actingAs($this->makeUser(User::ROLE_STAFF, StaffRole::Panitia));

        Livewire::test(ControlRoom::class)
            ->assertSee('Partisipasi per RT')
            ->assertSee($rtTwo->name)
            ->assertDontSee($this->candidate->name);
    }

    public function test_door_staff_and_other_election_committee_cannot_see_the_list(): void
    {
        $this->actingAs($this->superAdmin)->get(ControlRoom::getUrl())->assertOk()->assertSee('Peserta');

        $door = $this->makeUser(User::ROLE_STAFF, StaffRole::PetugasPintu);
        $this->actingAs($door)->get(ControlRoom::getUrl())->assertForbidden();

        app(AttendeeRegistrar::class)->register($this->election, 'Budi Santoso', null, $this->superAdmin);
        $otherElection = Election::factory()->create();
        $otherCommittee = $this->makeUser(User::ROLE_STAFF, StaffRole::Panitia, $otherElection);

        $this->actingAs($otherCommittee);
        Livewire::test(ControlRoom::class, ['electionId' => $this->election->public_id])
            ->assertDontSee('Budi Santoso');
    }

    public function test_export_downloads_sanitized_excel_and_is_audited(): void
    {
        app(AttendeeRegistrar::class)->register($this->election, '=HYPERLINK("http://x")', null, $this->superAdmin);
        $this->actingAs($this->makeUser(User::ROLE_STAFF, StaffRole::Panitia));

        Livewire::test(ControlRoom::class)
            ->callAction(TestAction::make('export')->table())
            ->assertFileDownloaded('daftar-hadir-'.$this->election->public_id.'.xlsx');

        $this->assertTrue(AuditLog::query()->where('action', 'attendance.exported')->exists());
        $this->assertSame(["'=HYPERLINK(\"http://x\")", 'Budi', 3], SpreadsheetSanitizer::row(['=HYPERLINK("http://x")', 'Budi', 3]));
    }

    /**
     * @return array{voted: Attendee, waiting: Attendee}
     */
    private function startWithOneVoter(): array
    {
        $registrar = app(AttendeeRegistrar::class);
        ['attendee' => $voted, 'pin' => $pin] = $registrar->register($this->election, 'Budi Santoso', null, $this->superAdmin);
        ['attendee' => $waiting] = $registrar->register($this->election, 'Mbah Karto', null, $this->superAdmin);
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);
        app(WaveManager::class)->open($this->election, WaveKind::Terbuka, 5, $this->superAdmin);
        $box = app(BallotBox::class);
        $box->cast($this->election, $voted, $box->verifyPin($this->election, $voted, $pin), $this->ballot, $this->candidate);

        return ['voted' => $voted, 'waiting' => $waiting];
    }

    public function test_new_pin_for_attendee_who_has_not_voted_shows_printable_card_without_cancelling_votes(): void
    {
        ['voted' => $voted, 'waiting' => $waiting] = $this->startWithOneVoter();
        $oldHash = $waiting->fresh()->pin_hash;
        $this->actingAs($this->makeUser(User::ROLE_STAFF, StaffRole::Panitia));

        $component = Livewire::test(ControlRoom::class)
            ->assertSee('Diberikan')
            ->call('setParticipantTab', 'semua')
            ->assertTableActionVisible('newPin', $waiting)
            ->assertTableActionHidden('newPin', $voted)
            ->assertTableActionHidden('restore', $waiting)
            ->assertTableActionVisible('restore', $voted)
            ->call('setParticipantTab', 'belum')
            ->callAction(TestAction::make('newPin')->table($waiting))
            ->assertSet('reissued.name', 'Mbah Karto')
            ->assertSet('reissued.cancelled', 0)
            ->assertSee('Cetak kartu PIN');

        $component->assertSeeHtml('<div class="pin">'.$component->get('reissued.pin').'</div>');
        $this->assertNotSame($oldHash, $waiting->fresh()->pin_hash);
        $this->assertSame(1, Vote::query()->where('status', VoteStatus::Sah)->count());
        $this->assertTrue(AuditLog::query()->where('action', 'attendee.voting_right_restored')->where('reason_code', RestoreReason::PinHilang->value)->exists());

        $component->call('acknowledgeReissue')->assertSet('reissued', null)->assertDontSee('Cetak kartu PIN');
    }

    public function test_new_pin_service_refuses_attendee_who_already_voted(): void
    {
        ['voted' => $voted] = $this->startWithOneVoter();

        $this->expectException(VotingException::class);

        app(VoterRightRestorer::class)->restore($this->election, $voted, RestoreReason::PinHilang, null, $this->superAdmin, onlyIfNotVoted: true);
    }

    public function test_restore_for_attendee_who_voted_cancels_the_vote_and_issues_new_pin(): void
    {
        ['voted' => $voted] = $this->startWithOneVoter();
        $this->actingAs($this->makeUser(User::ROLE_STAFF, StaffRole::Panitia));

        Livewire::test(ControlRoom::class)
            ->call('setParticipantTab', 'sudah')
            ->callAction(TestAction::make('restore')->table($voted), ['reason' => RestoreReason::NamaDipakaiOrangLain->value])
            ->assertHasNoFormErrors()
            ->assertSet('reissued.name', 'Budi Santoso')
            ->assertSet('reissued.cancelled', 1)
            ->assertSee('1 suara lama dibatalkan.');

        $this->assertSame(0, Vote::query()->where('status', VoteStatus::Sah)->count());
    }

    public function test_pin_buttons_are_hidden_before_the_election_starts(): void
    {
        $attendee = app(AttendeeRegistrar::class)->register($this->election, 'Budi Santoso', null, $this->superAdmin)['attendee'];
        $this->actingAs($this->makeUser(User::ROLE_STAFF, StaffRole::Panitia));

        Livewire::test(ControlRoom::class)
            ->assertTableActionHidden('newPin', $attendee)
            ->assertTableActionHidden('restore', $attendee);
    }
}
