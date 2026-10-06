<?php

namespace Tests\Feature;

use App\Enums\BallotScope;
use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Unit;
use App\Models\User;
use App\Services\Voting\ElectionLifecycle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ElectionReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Election $election;

    private Ballot $rtBallot;

    private Ballot $rwBallot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->election = Election::factory()->create(['mode' => ElectionMode::Resmi]);
        $this->rtBallot = Ballot::factory()->for($this->election)->create(['title' => 'Ketua RT', 'scope' => BallotScope::PerRt, 'max_candidates' => 5]);
        $this->rwBallot = Ballot::factory()->for($this->election)->create(['title' => 'Ketua RW', 'scope' => BallotScope::SemuaRt, 'max_candidates' => 3]);

        foreach (Unit::query()->orderBy('sort')->get() as $unit) {
            foreach (range(1, 3) as $number) {
                Candidate::factory()->for($this->rtBallot)->create(['number' => $number, 'unit_id' => $unit->id]);
            }
        }

        foreach (range(1, 3) as $number) {
            Candidate::factory()->for($this->rwBallot)->create(['number' => $number]);
        }
    }

    public function test_per_rt_limit_applies_to_each_rt_not_the_total(): void
    {
        $this->assertGreaterThan(5, $this->rtBallot->ballotCandidates()->count(), 'Total semua RT melebihi 5');
        $this->assertSame([], app(ElectionLifecycle::class)->readinessProblems($this->election));

        $admin = User::factory()->create();
        app(ElectionLifecycle::class)->markReady($this->election, $admin);
        $this->assertSame(ElectionStatus::Ready, $this->election->fresh()->status);
    }

    public function test_rt_and_rw_over_limit_are_reported(): void
    {
        $rt01 = Unit::query()->orderBy('sort')->firstOrFail();

        foreach (range(4, 6) as $number) {
            Candidate::factory()->for($this->rtBallot)->create(['number' => $number, 'unit_id' => $rt01->id]);
        }

        Candidate::factory()->for($this->rwBallot)->create(['number' => 4]);

        $this->assertSame([
            "Surat suara \"Ketua RT\" {$rt01->name} melebihi batas 5 kandidat per RT.",
            'Surat suara "Ketua RW" melebihi batas 3 kandidat.',
        ], app(ElectionLifecycle::class)->readinessProblems($this->election));
    }
}
