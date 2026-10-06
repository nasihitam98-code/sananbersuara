<?php

namespace Tests\Feature;

use App\Enums\BallotScope;
use App\Enums\DeviceKind;
use App\Enums\DeviceReleaseReason;
use App\Enums\ElectionMode;
use App\Enums\PermitCancelReason;
use App\Enums\PermitStatus;
use App\Enums\TpsPauseReason;
use App\Enums\VoteStatus;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Device;
use App\Models\Election;
use App\Models\Permit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vote;
use App\Models\Voter;
use App\Services\Devices\DeviceManager;
use App\Services\Permits\PermitManager;
use App\Services\Permits\TpsPauseService;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\VotingException;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ResmiVotingTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $deskOfficer;

    private Unit $rt03;

    private Unit $rt04;

    private Election $election;

    private Ballot $rtBallot;

    private Ballot $rwBallot;

    /** @var array<string, Candidate> */
    private array $candidates = [];

    private Voter $voter;

    private Device $desk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->superAdmin = $this->makeUser(User::ROLE_SUPER_ADMIN);
        $this->rt03 = Unit::query()->where('code', '03')->firstOrFail();
        $this->rt04 = Unit::query()->where('code', '04')->firstOrFail();
        $this->deskOfficer = $this->makeUser(User::ROLE_ADMIN_RT, $this->rt03, [User::PERMISSION_DESK]);

        $this->election = Election::factory()->create(['name' => 'Pemilihan RT dan RW', 'mode' => ElectionMode::Resmi, 'settings' => ['max_booths_per_unit' => 2]]);
        $this->rtBallot = Ballot::factory()->for($this->election)->create(['title' => 'Ketua RT', 'scope' => BallotScope::PerRt, 'sort' => 1]);
        $this->rwBallot = Ballot::factory()->for($this->election)->create(['title' => 'Ketua RW', 'scope' => BallotScope::SemuaRt, 'sort' => 2]);

        $this->candidates['rt03'] = Candidate::factory()->for($this->rtBallot)->create(['number' => 1, 'unit_id' => $this->rt03->id, 'name' => 'Calon RT Tiga']);
        $this->candidates['rt04'] = Candidate::factory()->for($this->rtBallot)->create(['number' => 1, 'unit_id' => $this->rt04->id, 'name' => 'Calon RT Empat']);
        $this->candidates['rw1'] = Candidate::factory()->for($this->rwBallot)->create(['number' => 1, 'name' => 'Calon RW Satu']);
        $this->candidates['rw2'] = Candidate::factory()->for($this->rwBallot)->create(['number' => 2, 'name' => 'Calon RW Dua']);

        $this->voter = Voter::factory()->for($this->rt03)->create(['name' => 'Budi Santoso']);
        Voter::factory()->count(2)->for($this->rt03)->create();
        Voter::factory()->for($this->rt04)->create();

        app(ElectionLifecycle::class)->markReady($this->election, $this->superAdmin);
        $this->desk = $this->pair(DeviceKind::Meja, 0)['device'];
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

    /**
     * @return array{device: Device, cookie: string}
     */
    private function pair(DeviceKind $kind, int $number, ?Unit $unit = null): array
    {
        $device = Device::query()->where('election_id', $this->election->id)->where('unit_id', ($unit ?? $this->rt03)->id)->where('kind', $kind)->where('number', $number)->firstOrFail();
        $token = app(DeviceManager::class)->issueToken($device, $this->superAdmin);
        $result = app(DeviceManager::class)->pair($token, "Laptop {$number}", Request::create('/'), $kind);

        return ['device' => $result['device'], 'cookie' => $result['device']->public_id.'|'.$result['secret']];
    }

    private function start(): void
    {
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);
    }

    public function test_full_booth_flow_rt_then_rw(): void
    {
        $booth = $this->pair(DeviceKind::Bilik, 1);
        $this->start();

        $this->withCookie(DeviceManager::COOKIE, $booth['cookie'])->get('/bilik/status')->assertJson(['state' => 'waiting']);

        $permit = app(PermitManager::class)->grant($this->voter, $this->deskOfficer, $this->desk);
        $this->assertSame($booth['device']->id, $permit->device_id);

        $this->withCookie(DeviceManager::COOKIE, $booth['cookie'])->get('/bilik/status')->assertJson(['state' => 'voting']);

        $this->withCookie(DeviceManager::COOKIE, $booth['cookie'])->get('/bilik/surat-suara')
            ->assertOk()
            ->assertSee('Ketua RT')
            ->assertSee('Calon RT Tiga')
            ->assertDontSee('Calon RT Empat')
            ->assertDontSee('Budi Santoso');

        $this->withCookie(DeviceManager::COOKIE, $booth['cookie'])
            ->post('/bilik/pilih', ['ballot' => $this->rtBallot->public_id, 'candidate' => $this->candidates['rt03']->public_id])
            ->assertRedirect(route('booth.ballot'));

        $this->withCookie(DeviceManager::COOKIE, $booth['cookie'])->get('/bilik/surat-suara')->assertSee('Ketua RW')->assertSee('Calon RW Dua');

        $this->withCookie(DeviceManager::COOKIE, $booth['cookie'])
            ->post('/bilik/pilih', ['ballot' => $this->rwBallot->public_id, 'candidate' => $this->candidates['rw2']->public_id])
            ->assertRedirect(route('booth.done'));

        $this->assertSame(2, Vote::query()->where('voter_id', $this->voter->id)->where('status', VoteStatus::Sah)->count());
        $this->assertSame(PermitStatus::Selesai, $permit->fresh()->status);
        $this->withCookie(DeviceManager::COOKIE, $booth['cookie'])->get('/bilik/status')->assertJson(['state' => 'waiting']);
    }

    public function test_forged_candidate_from_other_rt_is_rejected(): void
    {
        $booth = $this->pair(DeviceKind::Bilik, 1);
        $this->start();
        app(PermitManager::class)->grant($this->voter, $this->deskOfficer, $this->desk);

        $this->withCookie(DeviceManager::COOKIE, $booth['cookie'])
            ->post('/bilik/pilih', ['ballot' => $this->rtBallot->public_id, 'candidate' => $this->candidates['rt04']->public_id])
            ->assertSessionHasErrors('candidate');

        $this->assertSame(0, Vote::query()->count());
    }

    public function test_resubmitting_same_ballot_does_not_create_second_vote(): void
    {
        $booth = $this->pair(DeviceKind::Bilik, 1);
        $this->start();
        app(PermitManager::class)->grant($this->voter, $this->deskOfficer, $this->desk);
        $payload = ['ballot' => $this->rtBallot->public_id, 'candidate' => $this->candidates['rt03']->public_id];

        $this->withCookie(DeviceManager::COOKIE, $booth['cookie'])->post('/bilik/pilih', $payload);
        $this->withCookie(DeviceManager::COOKIE, $booth['cookie'])->post('/bilik/pilih', $payload);

        $this->assertSame(1, Vote::query()->where('ballot_id', $this->rtBallot->id)->count());
    }

    public function test_same_voter_cannot_be_sent_to_two_booths(): void
    {
        $this->pair(DeviceKind::Bilik, 1);
        $this->pair(DeviceKind::Bilik, 2);
        $this->start();
        $permits = app(PermitManager::class);
        $permits->grant($this->voter, $this->deskOfficer, $this->desk);

        $this->expectException(VotingException::class);
        $permits->grant($this->voter, $this->deskOfficer, $this->desk);
    }

    public function test_all_booths_busy(): void
    {
        $this->pair(DeviceKind::Bilik, 1);
        $this->start();
        $others = Voter::query()->where('unit_id', $this->rt03->id)->whereKeyNot($this->voter->id)->get();
        $permits = app(PermitManager::class);
        $permits->grant($others[0], $this->deskOfficer, $this->desk);

        $this->expectExceptionObject(VotingException::invalidState('Semua bilik terpakai. Tunggu bilik kosong.'));
        $permits->grant($others[1], $this->deskOfficer, $this->desk);
    }

    public function test_grant_requires_desk_officer_of_same_rt_on_desk_laptop(): void
    {
        $this->pair(DeviceKind::Bilik, 1);
        $this->start();
        $otherRtOfficer = $this->makeUser(User::ROLE_ADMIN_RT, $this->rt04, [User::PERMISSION_DESK]);
        $voterManagerOnly = $this->makeUser(User::ROLE_ADMIN_RT, $this->rt03, [User::PERMISSION_MANAGE_VOTERS]);

        foreach ([$otherRtOfficer, $voterManagerOnly, $this->superAdmin] as $user) {
            try {
                app(PermitManager::class)->grant($this->voter, $user, $this->desk);
                $this->fail('Seharusnya ditolak untuk '.$user->email);
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $booth = Device::query()->where('kind', DeviceKind::Bilik)->firstOrFail();

        $this->expectException(HttpException::class);
        app(PermitManager::class)->grant($this->voter, $this->deskOfficer, $booth);
    }

    public function test_unused_permit_expires_and_voter_can_be_permitted_again(): void
    {
        $this->pair(DeviceKind::Bilik, 1);
        $this->start();
        $permits = app(PermitManager::class);
        $permit = $permits->grant($this->voter, $this->deskOfficer, $this->desk);

        $this->travel(6)->minutes();
        Device::query()->update(['last_seen_at' => Carbon::now()]);
        $this->artisan('pemilihan:sapu-izin')->assertSuccessful();

        $this->assertSame(PermitStatus::Hangus, $permit->fresh()->status);
        $this->assertSame(PermitStatus::Aktif, $permits->grant($this->voter, $this->deskOfficer, $this->desk)->status);
    }

    public function test_cancel_permit_allowed_only_before_any_vote(): void
    {
        $booth = $this->pair(DeviceKind::Bilik, 1);
        $this->start();
        $permits = app(PermitManager::class);

        $permit = $permits->grant($this->voter, $this->deskOfficer, $this->desk);
        $permits->cancel($permit, PermitCancelReason::SalahKlikNama, $this->deskOfficer, $this->desk);
        $this->assertSame(PermitStatus::Dibatalkan, $permit->fresh()->status);

        $permit = $permits->grant($this->voter, $this->deskOfficer, $this->desk);
        $this->withCookie(DeviceManager::COOKIE, $booth['cookie'])
            ->post('/bilik/pilih', ['ballot' => $this->rtBallot->public_id, 'candidate' => $this->candidates['rt03']->public_id]);

        $this->expectException(VotingException::class);
        $permits->cancel($permit, PermitCancelReason::SalahKlikNama, $this->deskOfficer, $this->desk);
    }

    public function test_rt_done_rw_pending_after_booth_released(): void
    {
        $booth = $this->pair(DeviceKind::Bilik, 1);
        $this->start();
        $permits = app(PermitManager::class);
        $permit = $permits->grant($this->voter, $this->deskOfficer, $this->desk);

        $this->withCookie(DeviceManager::COOKIE, $booth['cookie'])
            ->post('/bilik/pilih', ['ballot' => $this->rtBallot->public_id, 'candidate' => $this->candidates['rt03']->public_id]);

        app(DeviceManager::class)->release($booth['device']->fresh(), DeviceReleaseReason::BateraiHabis, $this->deskOfficer);
        $this->assertSame(PermitStatus::Terhenti, $permit->fresh()->status);
        $this->withCookie(DeviceManager::COOKIE, $booth['cookie'])->get('/bilik/status')->assertJson(['state' => 'unpaired']);

        $replacement = $this->pair(DeviceKind::Bilik, 1);
        $permits->grant($this->voter, $this->deskOfficer, $this->desk);

        $this->withCookie(DeviceManager::COOKIE, $replacement['cookie'])->get('/bilik/surat-suara')
            ->assertSee('Ketua RW')
            ->assertDontSee('Calon RT Tiga');
    }

    public function test_device_tokens_are_single_use_and_expire(): void
    {
        $device = Device::query()->where('kind', DeviceKind::Bilik)->where('unit_id', $this->rt03->id)->where('number', 1)->firstOrFail();
        $manager = app(DeviceManager::class);

        $token = $manager->issueToken($device, $this->superAdmin);
        $manager->pair($token, 'Laptop A', Request::create('/'), DeviceKind::Bilik);

        try {
            $manager->pair($token, 'Laptop B', Request::create('/'), DeviceKind::Bilik);
            $this->fail('Token bekas seharusnya ditolak.');
        } catch (VotingException) {
            $this->assertTrue(true);
        }

        $expired = $manager->issueToken($device, $this->superAdmin);
        $this->travel(11)->minutes();

        $this->expectException(VotingException::class);
        $manager->pair($expired, 'Laptop C', Request::create('/'), DeviceKind::Bilik);
    }

    public function test_booth_token_cannot_pair_a_desk(): void
    {
        $device = Device::query()->where('kind', DeviceKind::Bilik)->where('unit_id', $this->rt03->id)->where('number', 1)->firstOrFail();
        $token = app(DeviceManager::class)->issueToken($device, $this->superAdmin);

        $this->post('/meja/pasang', ['token' => $token, 'label' => 'Laptop meja palsu'])->assertSessionHasErrors('token');
        $this->post('/bilik/pasang', ['token' => $token, 'label' => 'Laptop bilik'])
            ->assertRedirect(route('booth.show'))
            ->assertCookie(DeviceManager::COOKIE);
    }

    public function test_closing_election_stops_permits_and_releases_devices(): void
    {
        $this->pair(DeviceKind::Bilik, 1);
        $this->start();
        $permit = app(PermitManager::class)->grant($this->voter, $this->deskOfficer, $this->desk);

        app(ElectionLifecycle::class)->close($this->election, $this->superAdmin);

        $this->assertSame(PermitStatus::Terhenti, $permit->fresh()->status);
        $this->assertSame(0, Device::query()->whereNotNull('session_secret_hash')->count());
        $this->assertNull(Permit::query()->whereNotNull('active_voter_id')->first());
    }

    public function test_paused_tps_blocks_new_permits_but_voter_inside_can_finish(): void
    {
        $booth = $this->pair(DeviceKind::Bilik, 1);
        $this->pair(DeviceKind::Bilik, 2);
        $this->start();
        $permits = app(PermitManager::class);
        $pauses = app(TpsPauseService::class);
        $permit = $permits->grant($this->voter, $this->deskOfficer, $this->desk);

        $pauses->pause($this->election, $this->rt03, TpsPauseReason::InternetMati, null, $this->deskOfficer);

        try {
            $permits->grant(Voter::query()->where('unit_id', $this->rt03->id)->whereKeyNot($this->voter->id)->first(), $this->deskOfficer, $this->desk);
            $this->fail('Izin baru seharusnya ditolak saat TPS dijeda.');
        } catch (VotingException $exception) {
            $this->assertStringContainsString('dijeda', $exception->getMessage());
        }

        $this->withCookie(DeviceManager::COOKIE, $booth['cookie'])
            ->post('/bilik/pilih', ['ballot' => $this->rtBallot->public_id, 'candidate' => $this->candidates['rt03']->public_id])
            ->assertRedirect(route('booth.ballot'));

        $pauses->resume($this->election, $this->rt03, $this->superAdmin);
        $this->assertSame(PermitStatus::Dipakai, $permit->fresh()->status);
        $permits->grant(Voter::query()->where('unit_id', $this->rt03->id)->whereKeyNot($this->voter->id)->first(), $this->deskOfficer, $this->desk);

        $this->expectException(VotingException::class);
        $pauses->resume($this->election, $this->rt03, $this->superAdmin);
    }

    public function test_unknown_or_forged_device_cookie_is_rejected(): void
    {
        $this->start();

        $this->withCookie(DeviceManager::COOKIE, $this->desk->public_id.'|'.Str::random(48))->get('/bilik/status')->assertJson(['state' => 'unpaired']);
        $this->withCookie(DeviceManager::COOKIE, 'sembarang')->get('/bilik/surat-suara')->assertForbidden();
    }
}
