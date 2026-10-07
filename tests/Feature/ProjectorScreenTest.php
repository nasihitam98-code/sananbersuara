<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Enums\WaveKind;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\ElectionStaff;
use App\Models\User;
use App\Services\Voting\AttendeeRegistrar;
use App\Services\Voting\BallotBox;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\WaveManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectorScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $committee;

    private Election $election;

    private Ballot $ballot;

    private Candidate $candidate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->forceFill(['must_change_password' => false, 'has_email_authentication' => true])->save();
        $this->superAdmin->assignRole(User::ROLE_SUPER_ADMIN);

        $this->election = Election::factory()->create();
        $this->ballot = Ballot::factory()->for($this->election)->create();
        $this->candidate = Candidate::factory()->for($this->ballot)->create(['number' => 1, 'name' => 'Calon Rahasia']);
        app(ElectionLifecycle::class)->markReady($this->election, $this->superAdmin);

        $this->committee = User::factory()->create();
        $this->committee->forceFill(['must_change_password' => false, 'has_email_authentication' => true])->save();
        $this->committee->assignRole(User::ROLE_STAFF);
        $staff = new ElectionStaff(['user_id' => $this->committee->id, 'role' => StaffRole::Panitia]);
        $staff->election()->associate($this->election);
        $staff->save();
    }

    public function test_status_shows_live_participation_without_candidate_tallies(): void
    {
        $registrar = app(AttendeeRegistrar::class);
        ['attendee' => $voter, 'pin' => $pin] = $registrar->register($this->election, 'Budi Santoso', null, $this->superAdmin);
        $registrar->register($this->election, 'Mbah Karto', null, $this->superAdmin);

        $this->actingAs($this->committee)
            ->getJson(route('screens.qr.status', $this->election->public_id))
            ->assertOk()
            ->assertJson(['phase' => 'waiting', 'attendees' => 2, 'voted' => 0, 'had_waves' => false]);

        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);
        app(WaveManager::class)->open($this->election, WaveKind::Terbuka, 5, $this->superAdmin, 'Sesi pertama');
        $box = app(BallotBox::class);
        $box->cast($this->election, $voter, $box->verifyPin($this->election, $voter, $pin), $this->ballot, $this->candidate);

        $response = $this->getJson(route('screens.qr.status', $this->election->public_id))
            ->assertOk()
            ->assertJson(['phase' => 'open', 'wave_name' => 'Sesi pertama', 'attendees' => 2, 'voted' => 1, 'not_voted' => 1, 'percent' => 50]);

        $this->assertGreaterThan(0, $response->json('remaining'));
        $this->assertStringNotContainsString('Calon Rahasia', $response->getContent());

        app(ElectionLifecycle::class)->close($this->election, $this->superAdmin);

        $this->getJson(route('screens.qr.status', $this->election->public_id))->assertJson(['phase' => 'finished', 'remaining' => null]);
    }

    public function test_projector_page_renders_counts_and_status_url(): void
    {
        app(AttendeeRegistrar::class)->register($this->election, 'Budi Santoso', null, $this->superAdmin);

        $this->actingAs($this->committee)
            ->get(route('screens.qr', $this->election->public_id))
            ->assertOk()
            ->assertSee(route('screens.qr.status', $this->election->public_id), false)
            ->assertSee('Menunggu voting dibuka')
            ->assertSee('sudah memilih')
            ->assertDontSee('Calon Rahasia');
    }

    public function test_door_staff_cannot_read_projector_status(): void
    {
        $door = User::factory()->create();
        $door->forceFill(['must_change_password' => false, 'has_email_authentication' => true])->save();
        $door->assignRole(User::ROLE_STAFF);
        $staff = new ElectionStaff(['user_id' => $door->id, 'role' => StaffRole::PetugasPintu]);
        $staff->election()->associate($this->election);
        $staff->save();

        $this->actingAs($door)->getJson(route('screens.qr.status', $this->election->public_id))->assertForbidden();
    }
}
