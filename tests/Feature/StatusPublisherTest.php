<?php

namespace Tests\Feature;

use App\Enums\WaveKind;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\User;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\StatusPublisher;
use App\Services\Voting\WaveManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StatusPublisherTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Election $election;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->election = Election::factory()->create();
        Candidate::factory()->for(Ballot::factory()->for($this->election))->create(['number' => 1]);
        app(ElectionLifecycle::class)->markReady($this->election, $this->admin);
    }

    protected function tearDown(): void
    {
        File::delete(StatusPublisher::pathFor($this->election));

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function publishedStatus(): array
    {
        return json_decode((string) File::get(StatusPublisher::pathFor($this->election)), true);
    }

    public function test_static_status_file_follows_the_wave_lifecycle(): void
    {
        app(ElectionLifecycle::class)->start($this->election, $this->admin);
        $this->assertSame('waiting', $this->publishedStatus()['state']);

        $wave = app(WaveManager::class)->open($this->election, WaveKind::Terbuka, 5, $this->admin);
        $status = $this->publishedStatus();
        $this->assertSame('open', $status['state']);
        $this->assertSame($wave->ends_at->getTimestamp(), $status['ends_at']);

        app(WaveManager::class)->extend($this->election, 2, $this->admin);
        $this->assertSame($wave->fresh()->ends_at->getTimestamp(), $this->publishedStatus()['ends_at']);

        app(ElectionLifecycle::class)->pause($this->election, $this->admin, 'Uji jeda');
        $status = $this->publishedStatus();
        $this->assertSame('paused', $status['state']);
        $this->assertNull($status['ends_at']);
        $this->assertGreaterThan(0, $status['paused_remaining']);

        app(ElectionLifecycle::class)->resume($this->election, $this->admin);
        $this->assertSame('open', $this->publishedStatus()['state']);

        app(WaveManager::class)->close($this->election, $this->admin);
        $this->assertSame('waiting', $this->publishedStatus()['state']);

        app(ElectionLifecycle::class)->close($this->election, $this->admin);
        $this->assertSame('finished', $this->publishedStatus()['state']);
    }

    public function test_status_file_contains_no_personal_data(): void
    {
        app(ElectionLifecycle::class)->start($this->election, $this->admin);
        app(WaveManager::class)->open($this->election, WaveKind::Terbuka, 5, $this->admin);

        $this->assertSame(
            ['state', 'wave', 'round', 'ends_at', 'paused_remaining', 'assisted'],
            array_keys($this->publishedStatus()),
        );
    }
}
