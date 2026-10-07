<?php

namespace Tests\Feature;

use App\Enums\ElectionStatus;
use App\Enums\OutcomeStatus;
use App\Enums\ReportStatus;
use App\Enums\StaffRole;
use App\Enums\WaveKind;
use App\Filament\Pages\VerificationDesk;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\ElectionStaff;
use App\Models\User;
use App\Services\Results\OfficialReportService;
use App\Services\Results\ResultPublication;
use App\Services\Voting\AttendeeRegistrar;
use App\Services\Voting\BallotBox;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\VotingException;
use App\Services\Voting\WaveManager;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ResultPublicationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Election $election;

    private Ballot $ballot;

    /** @var array<int, Candidate> */
    private array $candidates;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['has_email_authentication' => true])->save();
        $this->admin->assignRole(User::ROLE_SUPER_ADMIN);

        $this->election = Election::factory()->create(['name' => 'Penjaringan Calon RW 2026']);
        $this->ballot = Ballot::factory()->for($this->election)->create(['title' => 'Calon Ketua RW']);
        $this->candidates = collect(range(1, 4))
            ->map(fn (int $number): Candidate => Candidate::factory()->for($this->ballot)->create(['number' => $number, 'name' => "Calon Nomor {$number}"]))
            ->all();

        $lifecycle = app(ElectionLifecycle::class);
        $lifecycle->markReady($this->election, $this->admin);

        $voters = [];

        foreach ([0, 0, 1, 2] as $index => $choice) {
            $voters[] = [app(AttendeeRegistrar::class)->register($this->election, "Warga {$index}", null, $this->admin), $choice];
        }

        $lifecycle->start($this->election, $this->admin);
        app(WaveManager::class)->open($this->election, WaveKind::Terbuka, 5, $this->admin);

        $box = app(BallotBox::class);

        foreach ($voters as [$registered, $choice]) {
            $wave = $box->verifyPin($this->election, $registered['attendee'], $registered['pin']);
            $box->cast($this->election, $registered['attendee'], $wave, $this->ballot, $this->candidates[$choice]);
        }

        $lifecycle->close($this->election, $this->admin);
    }

    private function publishFully(): void
    {
        $lifecycle = app(ElectionLifecycle::class);
        $lifecycle->startVerification($this->election, $this->admin);

        app(ResultPublication::class)->decide(
            $this->election, $this->ballot, null, OutcomeStatus::Ditetapkan,
            [$this->candidates[0]->id, $this->candidates[1]->id, $this->candidates[2]->id], null, $this->admin,
        );

        $reports = app(OfficialReportService::class);
        $reports->ratify($reports->createDraft($this->election, null, $this->admin), $this->admin);

        $lifecycle->publish($this->election, $this->admin);
    }

    public function test_publish_is_blocked_until_outcome_and_ratified_report_exist(): void
    {
        $lifecycle = app(ElectionLifecycle::class);
        $lifecycle->startVerification($this->election, $this->admin);

        $problems = app(ResultPublication::class)->publishProblems($this->election);
        $this->assertCount(2, $problems);

        $this->expectException(VotingException::class);
        $lifecycle->publish($this->election, $this->admin);
    }

    public function test_full_flow_publishes_only_selected_names_without_vote_counts(): void
    {
        $this->publishFully();

        $this->assertSame(ElectionStatus::Published, $this->election->fresh()->status);

        $this->get('/')->assertOk()->assertSee('Penjaringan Calon RW 2026');

        $this->get(route('public.show', $this->election->public_id))
            ->assertOk()
            ->assertSee('Lolos')
            ->assertSee('Calon Nomor 1')
            ->assertSee('Calon Nomor 3')
            ->assertDontSee('Calon Nomor 4')
            ->assertDontSee('suara sah', false)
            ->assertDontSee('%');
    }

    public function test_unpublished_result_shows_review_notice(): void
    {
        $this->publishFully();
        app(ElectionLifecycle::class)->unpublish($this->election, $this->admin, 'Ada pengaduan saksi');

        $this->get(route('public.show', $this->election->public_id))
            ->assertOk()
            ->assertSee('sedang ditinjau ulang')
            ->assertDontSee('Calon Nomor 1');
    }

    public function test_unpublished_elections_are_not_public(): void
    {
        $this->get(route('public.show', $this->election->public_id))->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('Penjaringan Calon RW 2026');
    }

    public function test_changing_outcome_after_ratification_requires_new_report(): void
    {
        app(ElectionLifecycle::class)->startVerification($this->election, $this->admin);
        $publication = app(ResultPublication::class);
        $reports = app(OfficialReportService::class);

        $publication->decide($this->election, $this->ballot, null, OutcomeStatus::Ditetapkan, [$this->candidates[0]->id], null, $this->admin);
        $first = $reports->createDraft($this->election, null, $this->admin);
        $reports->ratify($first, $this->admin);

        $publication->decide($this->election, $this->ballot, null, OutcomeStatus::Ditetapkan, [$this->candidates[1]->id], 'Koreksi penetapan', $this->admin);

        $this->assertSame(ReportStatus::Digantikan, $first->fresh()->status);
        $this->assertContains('Berita acara Keseluruhan belum disahkan.', $publication->publishProblems($this->election));
    }

    public function test_ratified_report_needs_reason_for_new_version_and_keeps_history(): void
    {
        app(ElectionLifecycle::class)->startVerification($this->election, $this->admin);
        $reports = app(OfficialReportService::class);
        $first = $reports->createDraft($this->election, null, $this->admin);
        $reports->ratify($first, $this->admin);

        try {
            $reports->createDraft($this->election, null, $this->admin);
            $this->fail('Versi baru tanpa alasan seharusnya ditolak.');
        } catch (VotingException) {
            $this->assertSame(ReportStatus::Disahkan, $first->fresh()->status);
        }

        $second = $reports->createDraft($this->election, null, $this->admin, 'Salah ketik catatan');
        $this->assertSame(2, $second->version);
        $this->assertSame(ReportStatus::Digantikan, $first->fresh()->status);
        $this->assertTrue($first->fresh()->checksumIsValid());
    }

    public function test_decide_rejects_candidates_from_another_ballot(): void
    {
        app(ElectionLifecycle::class)->startVerification($this->election, $this->admin);
        $outsider = Candidate::factory()->create();

        $this->expectExceptionObject(VotingException::invalidChoice());
        app(ResultPublication::class)->decide($this->election, $this->ballot, null, OutcomeStatus::Ditetapkan, [$outsider->id], null, $this->admin);
    }

    public function test_report_contains_totals_but_no_voter_identity(): void
    {
        app(ElectionLifecycle::class)->startVerification($this->election, $this->admin);
        $report = app(OfficialReportService::class)->createDraft($this->election, null, $this->admin);
        $data = $report->data();

        $this->assertSame(4, $data['ballots'][0]['valid']);
        $this->assertSame(2, $data['ballots'][0]['candidates'][0]['votes']);
        $this->assertStringNotContainsString('Warga 0', $report->content);
    }

    public function test_super_admin_runs_the_flow_from_the_verification_page(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin);

        Livewire::test(VerificationDesk::class)
            ->assertActionHidden(TestAction::make('decide')->arguments(['slot' => $this->ballot->id.':0']))
            ->callAction('startVerification')
            ->assertActionVisible(TestAction::make('decide')->arguments(['slot' => $this->ballot->id.':0']))
            ->callAction(TestAction::make('decide')->arguments(['slot' => $this->ballot->id.':0']), [
                'status' => OutcomeStatus::Ditetapkan->value,
                'candidates' => [$this->candidates[0]->id],
            ])
            ->callAction(TestAction::make('draftReport')->arguments(['unit' => 0]))
            ->assertSee('Terpilih');

        $report = app(OfficialReportService::class)->current($this->election, null);
        $this->assertSame(ReportStatus::Draft, $report->status);

        $this->get(VerificationDesk::getUrl())->assertOk()->assertSee($report->number);

        $outsider = User::factory()->create();
        $outsider->forceFill(['has_email_authentication' => true])->save();
        $outsider->assignRole(User::ROLE_STAFF);
        $this->actingAs($outsider)->get(VerificationDesk::getUrl())->assertForbidden();
    }

    public function test_report_page_requires_committee_role(): void
    {
        app(ElectionLifecycle::class)->startVerification($this->election, $this->admin);
        $report = app(OfficialReportService::class)->createDraft($this->election, null, $this->admin);

        $this->get(route('reports.show', $report->public_id))->assertRedirect('/admin/login');
        $this->actingAs($this->admin)->get(route('reports.show', $report->public_id))->assertOk()->assertSee($report->number);

        $outsider = User::factory()->create();
        $outsider->assignRole(User::ROLE_STAFF);
        $staff = new ElectionStaff(['user_id' => $outsider->id, 'role' => StaffRole::PetugasPintu]);
        $staff->election()->associate($this->election);
        $staff->save();

        $this->actingAs($outsider)->get(route('reports.show', $report->public_id))->assertForbidden();
    }
}
