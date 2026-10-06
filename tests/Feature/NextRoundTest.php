<?php

namespace Tests\Feature;

use App\Enums\BallotScope;
use App\Enums\DeviceKind;
use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\WaveKind;
use App\Filament\Pages\VerificationDesk;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Device;
use App\Models\Election;
use App\Models\Unit;
use App\Models\User;
use App\Models\Voter;
use App\Services\Devices\DeviceManager;
use App\Services\Permits\BoothBallotBox;
use App\Services\Permits\PermitManager;
use App\Services\Results\OfficialReportService;
use App\Services\Results\ResultSlots;
use App\Services\Voting\AttendeeRegistrar;
use App\Services\Voting\BallotBox;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\NextRoundService;
use App\Services\Voting\ResultsCalculator;
use App\Services\Voting\RoundResolver;
use App\Services\Voting\VotingException;
use App\Services\Voting\WaveManager;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\TestCase;

class NextRoundTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole(User::ROLE_SUPER_ADMIN);
    }

    public function test_dadakan_round_two_reuses_pins_and_limits_candidates(): void
    {
        $election = Election::factory()->create(['name' => 'Penjaringan']);
        $ballot = Ballot::factory()->for($election)->create(['title' => 'Calon Ketua RW']);
        [$a, $b, $c] = collect([1, 2, 3])->map(fn (int $number): Candidate => Candidate::factory()->for($ballot)->create(['number' => $number, 'name' => "Calon {$number}"]))->all();

        $lifecycle = app(ElectionLifecycle::class);
        $lifecycle->markReady($election, $this->superAdmin);
        $registered = collect(range(1, 4))->map(fn (int $i): array => app(AttendeeRegistrar::class)->register($election, "Warga {$i}", null, $this->superAdmin));
        $lifecycle->start($election, $this->superAdmin);
        app(WaveManager::class)->open($election, WaveKind::Terbuka, 5, $this->superAdmin);

        $box = app(BallotBox::class);

        foreach ([[0, $a], [1, $a], [2, $b], [3, $b]] as [$index, $candidate]) {
            $box->cast($election, $registered[$index]['attendee'], $box->verifyPin($election, $registered[$index]['attendee'], $registered[$index]['pin']), $ballot, $candidate);
        }

        $lifecycle->close($election, $this->superAdmin);
        $this->assertTrue(app(ResultsCalculator::class)->tally($ballot, $election->rounds()->first())['tie_at_top']);

        $round2 = app(NextRoundService::class)->open($election->fresh(), [ResultSlots::key($ballot, null)], [$a->id, $b->id], 'Hasil seri', $this->superAdmin);

        $this->assertSame(2, $round2->number);
        $this->assertSame(ElectionStatus::Berlangsung, $election->fresh()->status);

        app(WaveManager::class)->open($election->fresh(), WaveKind::Terbuka, 5, $this->superAdmin);

        $this->assertCount(4, $box->search($election, 'warga'), 'Semua peserta boleh memilih lagi di putaran 2');

        $wave = $box->verifyPin($election, $registered[0]['attendee'], $registered[0]['pin']);
        $this->assertSame($round2->id, $wave->round_id, 'PIN lama dipakai ulang');

        try {
            $box->cast($election, $registered[0]['attendee'], $wave, $ballot, $c);
            $this->fail('Calon yang tidak lolos ke putaran 2 seharusnya ditolak.');
        } catch (VotingException $exception) {
            $this->assertSame(VotingException::INVALID_CHOICE, $exception->reason);
        }

        $box->cast($election, $registered[0]['attendee'], $wave, $ballot, $a);
        $this->assertSame(1, app(ResultsCalculator::class)->tally($ballot, $round2)['valid']);
        $this->assertSame(4, app(ResultsCalculator::class)->tally($ballot, $election->rounds()->first())['valid'], 'Putaran 1 tetap utuh');
    }

    public function test_resmi_round_two_only_for_tied_rt_with_selected_candidates(): void
    {
        $rt03 = Unit::query()->where('code', '03')->firstOrFail();
        $rt04 = Unit::query()->where('code', '04')->firstOrFail();
        $officer = User::factory()->create(['unit_id' => null]);
        $officer->forceFill(['unit_id' => $rt03->id])->save();
        $officer->assignRole(User::ROLE_ADMIN_RT);
        $officer->givePermissionTo(User::PERMISSION_DESK);

        $election = Election::factory()->create(['mode' => ElectionMode::Resmi, 'settings' => ['max_booths_per_unit' => 1]]);
        $rt = Ballot::factory()->for($election)->create(['title' => 'Ketua RT', 'scope' => BallotScope::PerRt, 'sort' => 1]);
        $rw = Ballot::factory()->for($election)->create(['title' => 'Ketua RW', 'scope' => BallotScope::SemuaRt, 'sort' => 2]);
        $a = Candidate::factory()->for($rt)->create(['number' => 1, 'unit_id' => $rt03->id, 'name' => 'RT Tiga A']);
        $b = Candidate::factory()->for($rt)->create(['number' => 2, 'unit_id' => $rt03->id, 'name' => 'RT Tiga B']);
        $c = Candidate::factory()->for($rt)->create(['number' => 3, 'unit_id' => $rt03->id, 'name' => 'RT Tiga C']);
        Candidate::factory()->for($rt)->create(['number' => 1, 'unit_id' => $rt04->id]);
        $rwCandidate = Candidate::factory()->for($rw)->create(['number' => 1]);
        $voters = Voter::factory()->count(2)->for($rt03)->create();
        Voter::factory()->for($rt04)->create();

        $lifecycle = app(ElectionLifecycle::class);
        $lifecycle->markReady($election, $this->superAdmin);
        $lifecycle->start($election, $this->superAdmin);

        [$desk, $booth] = $this->pairDevices($election, $rt03);

        foreach ([[$voters[0], $a], [$voters[1], $b]] as [$voter, $choice]) {
            $permit = app(PermitManager::class)->grant($voter, $officer, $desk);
            app(BoothBallotBox::class)->cast($booth, $permit, $rt, $choice);
            app(BoothBallotBox::class)->cast($booth, $permit->fresh(), $rw, $rwCandidate);
        }

        $lifecycle->close($election, $this->superAdmin);

        try {
            app(NextRoundService::class)->open($election->fresh(), [ResultSlots::key($rt, $rt03)], [$a->id], 'Seri', $this->superAdmin);
            $this->fail('Minimal dua calon.');
        } catch (VotingException) {
            $this->assertSame(ElectionStatus::Ditutup, $election->fresh()->status);
        }

        $round2 = app(NextRoundService::class)->open($election->fresh(), [ResultSlots::key($rt, $rt03)], [$a->id, $b->id], 'Seri', $this->superAdmin);

        $resolver = app(RoundResolver::class);
        $this->assertTrue($resolver->covers($round2, $rt->id, $rt03->id));
        $this->assertFalse($resolver->covers($round2, $rt->id, $rt04->id));
        $this->assertFalse($resolver->covers($round2, $rw->id, null));

        [$desk, $booth] = $this->pairDevices($election, $rt03);
        $permit = app(PermitManager::class)->grant($voters[0], $officer, $desk);

        $this->assertSame(['Ketua RT'], app(PermitManager::class)->pendingBallots($voters[0], $election->fresh())->pluck('title')->all());

        $cookie = $booth->public_id.'|'.$this->boothSecret;
        $this->withCookie(DeviceManager::COOKIE, $cookie)->get('/bilik/surat-suara')
            ->assertSee('RT Tiga A')
            ->assertSee('RT Tiga B')
            ->assertDontSee('RT Tiga C');

        $this->expectException(VotingException::class);

        try {
            app(BoothBallotBox::class)->cast($booth, $permit, $rt, $c);
        } finally {
            app(BoothBallotBox::class)->cast($booth, $permit->fresh(), $rt, $a);
            $lifecycle->close($election->fresh(), $this->superAdmin);
            $lifecycle->startVerification($election->fresh(), $this->superAdmin);

            $report = app(OfficialReportService::class)->createDraft($election->fresh(), $rt03, $this->superAdmin)->data();
            $this->assertSame(2, $report['ballots'][0]['round']);
            $this->assertSame(1, $report['ballots'][0]['valid']);

            $overall = app(OfficialReportService::class)->createDraft($election->fresh(), null, $this->superAdmin)->data();
            $this->assertSame(1, $overall['ballots'][0]['round'], 'RW tetap memakai hasil putaran 1');
            $this->assertSame(2, $overall['ballots'][0]['valid']);
        }
    }

    public function test_super_admin_opens_next_round_from_verification_page(): void
    {
        $election = Election::factory()->create();
        $ballot = Ballot::factory()->for($election)->create(['title' => 'Calon Ketua RW']);
        $a = Candidate::factory()->for($ballot)->create(['number' => 1]);
        $b = Candidate::factory()->for($ballot)->create(['number' => 2]);
        $lifecycle = app(ElectionLifecycle::class);
        $lifecycle->markReady($election, $this->superAdmin);
        $lifecycle->start($election, $this->superAdmin);
        $lifecycle->close($election, $this->superAdmin);

        Filament::setCurrentPanel('admin');
        $this->superAdmin->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $this->actingAs($this->superAdmin);

        Livewire::test(VerificationDesk::class)
            ->callAction('nextRound', [
                'slots' => [ResultSlots::key($ballot, null)],
                'candidates' => [$a->id, $b->id],
                'reason' => 'Seri',
                'current_password' => 'password',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(2, $election->rounds()->max('number'));
        $this->assertSame(ElectionStatus::Berlangsung, $election->fresh()->status);
    }

    private string $boothSecret = '';

    /**
     * @return array{0: Device, 1: Device}
     */
    private function pairDevices(Election $election, Unit $unit): array
    {
        $manager = app(DeviceManager::class);
        $desk = Device::query()->where('election_id', $election->id)->where('unit_id', $unit->id)->where('kind', DeviceKind::Meja)->firstOrFail();
        $booth = Device::query()->where('election_id', $election->id)->where('unit_id', $unit->id)->where('kind', DeviceKind::Bilik)->firstOrFail();

        $manager->pair($manager->issueToken($desk, $this->superAdmin), 'Meja', Request::create('/'), DeviceKind::Meja);
        $paired = $manager->pair($manager->issueToken($booth, $this->superAdmin), 'Bilik', Request::create('/'), DeviceKind::Bilik);
        $this->boothSecret = $paired['secret'];

        return [$desk->fresh(), $booth->fresh()];
    }
}
