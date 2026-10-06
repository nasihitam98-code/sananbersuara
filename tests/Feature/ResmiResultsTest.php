<?php

namespace Tests\Feature;

use App\Enums\BallotScope;
use App\Enums\CorrectionReason;
use App\Enums\CorrectionStatus;
use App\Enums\DeviceKind;
use App\Enums\ElectionMode;
use App\Enums\OutcomeStatus;
use App\Enums\VoteStatus;
use App\Filament\Pages\CorrectionsPage;
use App\Filament\Pages\ParticipationPage;
use App\Filament\Pages\ResultScreen;
use App\Filament\Pages\VoteDetailPage;
use App\Models\AuditLog;
use App\Models\Ballot;
use App\Models\BallotVoter;
use App\Models\Candidate;
use App\Models\Device;
use App\Models\Election;
use App\Models\Permit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vote;
use App\Models\Voter;
use App\Services\Corrections\CorrectionService;
use App\Services\Devices\DeviceManager;
use App\Services\Permits\BoothBallotBox;
use App\Services\Permits\PermitManager;
use App\Services\Results\DataRetention;
use App\Services\Results\OfficialReportService;
use App\Services\Results\ResultPublication;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\ResultsCalculator;
use App\Services\Voting\VotingException;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ResmiResultsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $secondSuperAdmin;

    private User $deskRt03;

    private User $adminRt03;

    private Unit $rt03;

    private Unit $rt04;

    private Election $election;

    private Ballot $rtBallot;

    private Ballot $rwBallot;

    /** @var array<string, Candidate> */
    private array $candidates = [];

    /** @var array<string, Device> */
    private array $desks = [];

    /** @var array<string, Device> */
    private array $booths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->rt03 = Unit::query()->where('code', '03')->firstOrFail();
        $this->rt04 = Unit::query()->where('code', '04')->firstOrFail();
        $this->superAdmin = $this->makeUser(User::ROLE_SUPER_ADMIN);
        $this->secondSuperAdmin = $this->makeUser(User::ROLE_SUPER_ADMIN);
        $this->deskRt03 = $this->makeUser(User::ROLE_ADMIN_RT, $this->rt03, [User::PERMISSION_DESK]);
        $this->adminRt03 = $this->makeUser(User::ROLE_ADMIN_RT, $this->rt03, [User::PERMISSION_MANAGE_VOTERS]);
        $deskRt04 = $this->makeUser(User::ROLE_ADMIN_RT, $this->rt04, [User::PERMISSION_DESK]);

        $this->election = Election::factory()->create(['name' => 'Pemilihan RT dan RW 2026', 'mode' => ElectionMode::Resmi, 'settings' => ['max_booths_per_unit' => 1]]);
        $this->rtBallot = Ballot::factory()->for($this->election)->create(['title' => 'Ketua RT', 'scope' => BallotScope::PerRt, 'sort' => 1]);
        $this->rwBallot = Ballot::factory()->for($this->election)->create(['title' => 'Ketua RW', 'scope' => BallotScope::SemuaRt, 'sort' => 2]);

        $this->candidates['rt03'] = Candidate::factory()->for($this->rtBallot)->create(['number' => 1, 'unit_id' => $this->rt03->id, 'name' => 'Calon RT Tiga']);
        $this->candidates['rt04'] = Candidate::factory()->for($this->rtBallot)->create(['number' => 1, 'unit_id' => $this->rt04->id, 'name' => 'Calon RT Empat']);
        $this->candidates['rw1'] = Candidate::factory()->for($this->rwBallot)->create(['number' => 1, 'name' => 'Calon RW Satu']);
        $this->candidates['rw2'] = Candidate::factory()->for($this->rwBallot)->create(['number' => 2, 'name' => 'Calon RW Dua']);

        Voter::factory()->count(3)->for($this->rt03)->create();
        Voter::factory()->count(2)->for($this->rt04)->create();

        app(ElectionLifecycle::class)->markReady($this->election, $this->superAdmin);

        foreach ([$this->rt03, $this->rt04] as $unit) {
            $this->desks[$unit->code] = $this->pair(DeviceKind::Meja, 0, $unit);
            $this->booths[$unit->code] = $this->pair(DeviceKind::Bilik, 1, $unit);
        }

        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);

        // RT 03: 3 pemilih memilih RT + RW; RT 04: 1 dari 2 memilih.
        foreach (Voter::query()->where('unit_id', $this->rt03->id)->get() as $index => $voter) {
            $this->vote($voter, $this->deskRt03, $this->desks['03'], $this->booths['03'], $this->candidates['rt03'], $index === 0 ? $this->candidates['rw1'] : $this->candidates['rw2']);
        }

        $this->vote(Voter::query()->where('unit_id', $this->rt04->id)->first(), $deskRt04, $this->desks['04'], $this->booths['04'], $this->candidates['rt04'], $this->candidates['rw2']);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function makeUser(string $role, ?Unit $unit = null, array $permissions = []): User
    {
        $user = User::factory()->create();
        $user->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP', 'unit_id' => $unit?->id])->save();
        $user->assignRole($role);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function pair(DeviceKind $kind, int $number, Unit $unit): Device
    {
        $device = Device::query()->where('election_id', $this->election->id)->where('unit_id', $unit->id)->where('kind', $kind)->where('number', $number)->firstOrFail();
        $manager = app(DeviceManager::class);

        return $manager->pair($manager->issueToken($device, $this->superAdmin), 'Laptop', Request::create('/'), $kind)['device'];
    }

    private function vote(Voter $voter, User $officer, Device $desk, Device $booth, Candidate $rt, Candidate $rw): void
    {
        Device::query()->update(['last_seen_at' => now()]);
        $permit = app(PermitManager::class)->grant($voter, $officer, $desk->fresh());
        $box = app(BoothBallotBox::class);
        $box->cast($booth->fresh(), $permit, $this->rtBallot, $rt);
        $box->cast($booth->fresh(), $permit->fresh(), $this->rwBallot, $rw);
    }

    public function test_participation_counts_eligible_per_rt_without_candidate_numbers(): void
    {
        $this->actingAs($this->adminRt03);

        Livewire::test(ParticipationPage::class)
            ->assertSee('Ketua RT')
            ->assertSee('100%')
            ->assertDontSee('Calon RW Dua')
            ->assertDontSee('RT 04');

        $this->actingAs($this->superAdmin);
        Livewire::test(ParticipationPage::class)->assertSee('RT 04')->assertSee('50%');
    }

    public function test_correction_flow_respects_k17_and_allows_revote_while_live(): void
    {
        $service = app(CorrectionService::class);
        $voter = Voter::query()->where('unit_id', $this->rt03->id)->first();

        try {
            $service->request($this->election, $voter, $this->rwBallot, CorrectionReason::SalahOrang, null, $this->deskRt03);
            $this->fail('Pemberi izin tidak boleh mengajukan (K17).');
        } catch (VotingException $exception) {
            $this->assertStringContainsString('K17', $exception->getMessage());
        }

        $correction = $service->request($this->election, $voter, $this->rwBallot, CorrectionReason::SalahOrang, null, $this->adminRt03);
        $this->assertSame(CorrectionStatus::Diajukan, $correction->status);
        $this->assertTrue($this->secondSuperAdmin->notifications()->exists() || $this->superAdmin->notifications()->exists());

        $service->approve($correction, $this->superAdmin);

        $this->assertSame(1, Vote::query()->where('voter_id', $voter->id)->where('ballot_id', $this->rwBallot->id)->where('status', VoteStatus::Dibatalkan)->count());
        $this->assertSame(['Ketua RW'], app(PermitManager::class)->pendingBallots($voter, $this->election)->pluck('title')->all());

        Device::query()->update(['last_seen_at' => now()]);
        $permit = app(PermitManager::class)->grant($voter, $this->deskRt03, $this->desks['03']->fresh());
        app(BoothBallotBox::class)->cast($this->booths['03']->fresh(), $permit, $this->rwBallot, $this->candidates['rw2']);

        $this->assertSame(1, Vote::query()->where('voter_id', $voter->id)->where('ballot_id', $this->rwBallot->id)->where('status', VoteStatus::Sah)->count());
        $this->assertSame(1, Vote::query()->where('voter_id', $voter->id)->where('ballot_id', $this->rtBallot->id)->count(), 'Surat suara RT tidak diulang');
    }

    public function test_admin_rt_cannot_request_correction_for_another_rt(): void
    {
        $foreign = Voter::query()->where('unit_id', $this->rt04->id)->first();

        $this->expectException(HttpException::class);
        app(CorrectionService::class)->request($this->election, $foreign, $this->rwBallot, CorrectionReason::GangguanTeknis, null, $this->adminRt03);
    }

    public function test_correction_page_lists_only_own_rt_and_never_shows_choice(): void
    {
        $voter = Voter::query()->where('unit_id', $this->rt03->id)->first();
        app(CorrectionService::class)->request($this->election, $voter, $this->rwBallot, CorrectionReason::GangguanTeknis, null, $this->adminRt03);

        $this->actingAs($this->adminRt03);
        Livewire::test(CorrectionsPage::class)
            ->assertSee($voter->name)
            ->assertDontSee('Calon RW Satu')
            ->assertActionHidden(TestAction::make('approve')->arguments(['correction' => 'x']));
    }

    public function test_vote_detail_only_after_close_with_reason_and_is_audited(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(VoteDetailPage::class)->assertSee('Belum ada pemilihan Mode Resmi yang ditutup');

        app(ElectionLifecycle::class)->close($this->election, $this->superAdmin);

        Livewire::test(VoteDetailPage::class)
            ->callAction('open', ['election' => $this->election->id, 'reason' => 'AUDIT', 'current_password' => 'password'])
            ->assertHasNoFormErrors()
            ->assertSee('Calon RT Tiga')
            ->set('ballotFilter', $this->rwBallot->id)
            ->assertSee('Calon RW Satu');

        $this->assertTrue(AuditLog::query()->where('action', 'vote_detail.opened')->where('reason_code', 'AUDIT')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'vote_detail.viewed')->exists());
        $this->assertTrue($this->secondSuperAdmin->notifications()->where('data', 'like', '%Detail Suara dibuka%')->exists());

        $this->actingAs($this->adminRt03)->get(VoteDetailPage::getUrl())->assertForbidden();
    }

    public function test_vote_detail_flag_cannot_be_set_from_browser(): void
    {
        app(ElectionLifecycle::class)->close($this->election, $this->superAdmin);
        $this->actingAs($this->superAdmin);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(VoteDetailPage::class)->set('openedElectionId', $this->election->id);
    }

    public function test_reports_per_rt_and_publication_of_rt_and_rw_results(): void
    {
        $lifecycle = app(ElectionLifecycle::class);
        $lifecycle->close($this->election, $this->superAdmin);
        $lifecycle->startVerification($this->election, $this->superAdmin);

        $publication = app(ResultPublication::class);
        $publication->decide($this->election, $this->rtBallot, $this->rt03, OutcomeStatus::Ditetapkan, [$this->candidates['rt03']->id], null, $this->superAdmin);
        $publication->decide($this->election, $this->rtBallot, $this->rt04, OutcomeStatus::Ditetapkan, [$this->candidates['rt04']->id], null, $this->superAdmin);
        $publication->decide($this->election, $this->rwBallot, null, OutcomeStatus::Ditetapkan, [$this->candidates['rw2']->id], null, $this->superAdmin);

        $reports = app(OfficialReportService::class);
        $rt03Report = $reports->createDraft($this->election, $this->rt03, $this->superAdmin);
        $data = $rt03Report->data();

        $this->assertSame(3, $data['ballots'][0]['participation']['eligible']);
        $this->assertSame(3, $data['ballots'][0]['participation']['voted']);
        $this->assertSame(0, $data['reconciliation']['votes_without_permit']);
        $this->assertSame(6, $data['reconciliation']['per_booth']['RT03-01']);

        foreach ([null, $this->rt03, $this->rt04] as $scope) {
            $report = $reports->current($this->election, $scope) ?? $reports->createDraft($this->election, $scope, $this->superAdmin);
            $reports->ratify($report, $this->superAdmin);
        }

        $lifecycle->publish($this->election, $this->superAdmin);

        $this->get(route('public.show', [$this->election->public_id, 'rt' => '04']))
            ->assertOk()
            ->assertSee('Calon RT Empat')
            ->assertSee('Calon RW Dua')
            ->assertDontSee('Calon RT Tiga');

        $this->actingAs($this->adminRt03)->get(route('reports.show', $rt03Report->public_id))->assertOk();
        $this->actingAs($this->adminRt03)->get(route('reports.show', $reports->current($this->election, $this->rt04)->public_id))->assertForbidden();
    }

    private function publishAll(): void
    {
        $lifecycle = app(ElectionLifecycle::class);
        $lifecycle->close($this->election, $this->superAdmin);
        $lifecycle->startVerification($this->election, $this->superAdmin);

        $publication = app(ResultPublication::class);
        $publication->decide($this->election, $this->rtBallot, $this->rt03, OutcomeStatus::Ditetapkan, [$this->candidates['rt03']->id], null, $this->superAdmin);
        $publication->decide($this->election, $this->rtBallot, $this->rt04, OutcomeStatus::Ditetapkan, [$this->candidates['rt04']->id], null, $this->superAdmin);
        $publication->decide($this->election, $this->rwBallot, null, OutcomeStatus::Ditetapkan, [$this->candidates['rw2']->id], null, $this->superAdmin);

        $reports = app(OfficialReportService::class);

        foreach ([null, $this->rt03, $this->rt04] as $scope) {
            $reports->ratify($reports->createDraft($this->election, $scope, $this->superAdmin), $this->superAdmin);
        }

        $lifecycle->publish($this->election, $this->superAdmin);
    }

    public function test_vote_linkage_is_purged_after_retention_but_participation_survives(): void
    {
        $this->publishAll();
        $calculator = app(ResultsCalculator::class);
        $round = $this->election->currentRound();

        $this->travel(20)->days();
        $this->artisan('pemilihan:retensi')->assertSuccessful();
        $this->assertSame(8, Vote::query()->where('election_id', $this->election->id)->whereNotNull('voter_id')->count(), 'Belum lewat 30 hari');

        app(DataRetention::class)->extendLinkage($this->election->fresh(), 14, 'Ada sengketa', $this->superAdmin);
        $this->travel(15)->days();
        $this->artisan('pemilihan:retensi')->assertSuccessful();
        $this->assertSame(8, Vote::query()->whereNotNull('voter_id')->count(), 'Diperpanjang 14 hari');

        $this->travel(10)->days();
        $this->artisan('pemilihan:retensi')->assertSuccessful();

        $this->assertSame(0, Vote::query()->where('election_id', $this->election->id)->whereNotNull('voter_id')->count());
        $this->assertSame(0, Vote::query()->whereNotNull('permit_id')->count());
        $this->assertNotNull($this->election->fresh()->vote_links_destroyed_at);
        $this->assertSame(3, $calculator->ballotParticipation($this->rtBallot, $round, $this->rt03->id)['voted']);
        $this->assertSame(1, $calculator->ballotParticipation($this->rwBallot, $round, $this->rt04->id)['voted']);
        $this->assertSame(4, app(ResultsCalculator::class)->tally($this->rwBallot, $round)['valid'], 'Hasil tetap utuh');

        $this->actingAs($this->superAdmin);
        Livewire::test(VoteDetailPage::class)->assertSee('keterkaitannya sudah dihapus');
    }

    public function test_personal_data_is_anonymised_after_one_year(): void
    {
        $this->publishAll();

        $this->travel(366)->days();
        $this->artisan('pemilihan:retensi')->assertSuccessful();

        $this->assertSame(0, BallotVoter::query()->count());
        $this->assertSame(0, Permit::query()->where('election_id', $this->election->id)->count());
        $this->assertNotNull($this->election->fresh()->personal_data_purged_at);
        $this->assertSame(4, app(ResultsCalculator::class)->tally($this->rwBallot, $this->election->currentRound())['valid']);
        $this->assertTrue(AuditLog::query()->where('action', 'retention.personal_data_purged')->exists());
    }

    public function test_result_screen_for_admin_rt_shows_own_rt_and_rw_total_only(): void
    {
        app(ElectionLifecycle::class)->close($this->election, $this->superAdmin);
        $this->actingAs($this->adminRt03);

        Livewire::test(ResultScreen::class)
            ->call('reveal')
            ->assertSee('Ketua RT — RT 03')
            ->assertSee('Ketua RW')
            ->assertDontSee('Calon RT Empat');
    }

    public function test_recap_export_is_restricted_and_after_close_only(): void
    {
        $this->actingAs($this->superAdmin)->get(route('recap.export', $this->election->public_id))->assertForbidden();

        app(ElectionLifecycle::class)->close($this->election, $this->superAdmin);

        $this->actingAs($this->superAdmin)->get(route('recap.export', $this->election->public_id))->assertOk()->assertDownload();
        $this->actingAs($this->adminRt03)->get(route('recap.export', $this->election->public_id))->assertOk();

        $outsider = $this->makeUser(User::ROLE_STAFF);
        $this->actingAs($outsider)->get(route('recap.export', $this->election->public_id))->assertForbidden();
    }
}
