<?php

namespace Tests\Feature;

use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\User;
use App\Services\Voting\ElectionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckDeploySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_deploy_is_allowed_when_no_election_is_live(): void
    {
        Election::factory()->create();

        $this->artisan('pemilihan:cek-deploy')->assertSuccessful();
    }

    public function test_deploy_is_blocked_while_an_election_is_live(): void
    {
        $admin = User::factory()->create();
        $election = Election::factory()->create(['name' => 'Penjaringan Calon RW']);
        Candidate::factory()->for(Ballot::factory()->for($election))->create(['number' => 1]);
        app(ElectionLifecycle::class)->markReady($election, $admin);
        app(ElectionLifecycle::class)->start($election, $admin);

        $this->artisan('pemilihan:cek-deploy')
            ->expectsOutputToContain('Penjaringan Calon RW')
            ->assertFailed();
    }
}
