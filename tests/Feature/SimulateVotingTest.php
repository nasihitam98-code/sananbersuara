<?php

namespace Tests\Feature;

use App\Enums\BallotScope;
use App\Enums\DeviceKind;
use App\Enums\ElectionMode;
use App\Enums\VoteStatus;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Device;
use App\Models\Election;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vote;
use App\Models\Voter;
use App\Services\Devices\DeviceManager;
use App\Services\Voting\ElectionLifecycle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SimulateVotingTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Election $election;

    private Unit $rt03;

    private Unit $rt04;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->rt03 = Unit::query()->where('code', '03')->firstOrFail();
        $this->rt04 = Unit::query()->where('code', '04')->firstOrFail();
        $this->superAdmin = $this->makeUser(User::ROLE_SUPER_ADMIN);
        $this->makeUser(User::ROLE_ADMIN_RT, $this->rt03);
        $this->makeUser(User::ROLE_ADMIN_RT, $this->rt04);

        $this->election = Election::factory()->create(['name' => 'Gladi RT RW', 'mode' => ElectionMode::Resmi, 'settings' => ['max_booths_per_unit' => 2]]);
        $rtBallot = Ballot::factory()->for($this->election)->create(['title' => 'Ketua RT', 'scope' => BallotScope::PerRt, 'sort' => 1]);
        $rwBallot = Ballot::factory()->for($this->election)->create(['title' => 'Ketua RW', 'scope' => BallotScope::SemuaRt, 'sort' => 2]);

        foreach ([$this->rt03, $this->rt04] as $unit) {
            Candidate::factory()->for($rtBallot)->create(['number' => 1, 'unit_id' => $unit->id]);
            Candidate::factory()->for($rtBallot)->create(['number' => 2, 'unit_id' => $unit->id]);
        }

        Candidate::factory()->for($rwBallot)->create(['number' => 1]);
        Candidate::factory()->for($rwBallot)->create(['number' => 2]);
        Voter::factory()->for($this->rt03)->count(5)->create();
        Voter::factory()->for($this->rt04)->count(3)->create();

        app(ElectionLifecycle::class)->markReady($this->election, $this->superAdmin);
    }

    private function makeUser(string $role, ?Unit $unit = null): User
    {
        $user = User::factory()->create();
        $user->forceFill(['has_email_authentication' => true, 'unit_id' => $unit?->id])->save();
        $user->assignRole($role);

        if ($unit !== null) {
            $user->givePermissionTo(User::PERMISSION_DESK);
        }

        return $user;
    }

    public function test_every_voter_votes_through_their_rt_desk_and_booth_then_devices_are_released(): void
    {
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);

        $this->artisan('pemilihan:simulasi', ['--konfirmasi' => 'Gladi RT RW', '--menit' => 0])
            ->expectsOutputToContain('8 pemilih akan mencoblos')
            ->expectsOutputToContain('Selesai: 8 pemilih mencoblos')
            ->assertSuccessful();

        // 8 pemilih x 2 surat suara; calon RT selalu dari RT pemilih sendiri.
        $this->assertSame(16, Vote::query()->count());
        Vote::query()->with(['candidate', 'voter', 'ballot'])->get()
            ->filter(fn (Vote $vote): bool => $vote->ballot->scope === BallotScope::PerRt)
            ->each(fn (Vote $vote) => $this->assertSame($vote->voter->unit_id, $vote->candidate->unit_id));

        // Suara lewat bilik RT pemilih, laptop simulasi dilepas lagi.
        Vote::query()->with(['voter'])->get()->each(function (Vote $vote): void {
            $this->assertSame($vote->voter->unit_id, Device::query()->find($vote->device_id)->unit_id);
        });
        $this->assertSame(0, Device::query()->whereNotNull('session_secret_hash')->count());

        // Dijalankan lagi: tidak ada yang mencoblos dua kali.
        $this->artisan('pemilihan:simulasi', ['--konfirmasi' => 'Gladi RT RW', '--menit' => 0])
            ->expectsOutputToContain('0 pemilih akan mencoblos')
            ->assertSuccessful();
        $this->assertSame(16, Vote::query()->count());
    }

    public function test_refuses_without_the_exact_name_or_when_real_laptops_already_took_votes(): void
    {
        $manager = app(DeviceManager::class);
        $booth = Device::query()->where('unit_id', $this->rt03->id)->where('kind', DeviceKind::Bilik)->orderBy('number')->firstOrFail();
        $manager->pair($manager->issueToken($booth, $this->superAdmin), 'Laptop Bilik Asli', Request::create('/'), DeviceKind::Bilik);
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);

        $this->artisan('pemilihan:simulasi', ['--konfirmasi' => 'salah', '--menit' => 0])->assertFailed();

        $voter = Voter::query()->where('unit_id', $this->rt03->id)->firstOrFail();
        $ballot = $this->election->ballots()->where('scope', BallotScope::SemuaRt)->firstOrFail();
        (new Vote)->forceFill([
            'election_id' => $this->election->id,
            'ballot_id' => $ballot->id,
            'round_id' => $this->election->currentRound()->id,
            'candidate_id' => $ballot->candidates()->firstOrFail()->id,
            'voter_id' => $voter->id,
            'device_id' => $booth->id,
            'status' => VoteStatus::Sah,
            'active_key' => 1,
        ])->save();

        $this->artisan('pemilihan:simulasi', ['--konfirmasi' => 'Gladi RT RW', '--menit' => 0])
            ->expectsOutputToContain('suara dari laptop asli')
            ->assertFailed();

        // Laptop asli tidak dilepas atau dipakai simulasi.
        $this->assertSame('Laptop Bilik Asli', $booth->fresh()->label);
        $this->assertTrue($booth->fresh()->isPaired());
    }
}
