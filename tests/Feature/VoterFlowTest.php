<?php

namespace Tests\Feature;

use App\Enums\WaveKind;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vote;
use App\Services\Voting\AttendeeRegistrar;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\WaveManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoterFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Election $election;

    private Ballot $ballot;

    private Candidate $candidate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->election = Election::factory()->create(['name' => 'Penjaringan Calon RW']);
        $this->ballot = Ballot::factory()->for($this->election)->create();
        $this->candidate = Candidate::factory()->for($this->ballot)->create([
            'number' => 7,
            'name' => 'Bapak Sutrisno',
            'origin_unit_id' => Unit::factory()->create(['code' => '03', 'name' => 'RT 03'])->id,
        ]);
        Candidate::factory()->for($this->ballot)->create(['number' => 8]);

        app(ElectionLifecycle::class)->markReady($this->election, $this->admin);
    }

    private function url(string $path = ''): string
    {
        return "/v/{$this->election->access_code}{$path}";
    }

    public function test_full_flow_from_waiting_to_done(): void
    {
        ['attendee' => $attendee, 'pin' => $pin] = app(AttendeeRegistrar::class)->register($this->election, 'Budi Santoso', null, $this->admin);
        app(ElectionLifecycle::class)->start($this->election, $this->admin);

        $this->get($this->url())
            ->assertOk()
            ->assertSee('Menunggu pemungutan dibuka')
            ->assertHeader('Cache-Control')
            ->assertHeader('Content-Security-Policy');

        $this->getJson($this->url('/cari?q=budi'))->assertStatus(409);

        app(WaveManager::class)->open($this->election, WaveKind::Terbuka, 5, $this->admin);

        $this->get($this->url())->assertSee('Cari nama Anda');
        $this->getJson($this->url('/status'))->assertJson(['state' => 'open']);

        $this->getJson($this->url('/cari?q=bu'))->assertJson(['results' => []]);
        $this->getJson($this->url('/cari?q=budi'))
            ->assertOk()
            ->assertJsonPath('results.0.id', $attendee->public_id)
            ->assertJsonPath('results.0.name', 'Budi Santoso');

        $this->get($this->url("/pin/{$attendee->public_id}"))->assertOk()->assertSee('Budi Santoso');

        $this->post($this->url("/pin/{$attendee->public_id}"), ['pin' => $pin])
            ->assertRedirect(route('voter.ballot', $this->election->access_code));

        $this->get($this->url('/surat-suara'))
            ->assertOk()
            ->assertSee('Bapak Sutrisno')
            ->assertSee('Asal RT 03')
            ->assertSee('KONFIRMASI PILIHAN');

        $this->post($this->url('/surat-suara'), [
            'ballot' => $this->ballot->public_id,
            'candidate' => $this->candidate->public_id,
        ])->assertRedirect(route('voter.ballot', $this->election->access_code));

        $this->get($this->url('/surat-suara'))->assertRedirect(route('voter.done', $this->election->access_code));
        $this->get($this->url('/selesai'))->assertOk()->assertSee('Sudah memilih');

        $this->assertSame(1, Vote::query()->count());
        $this->getJson($this->url('/cari?q=budi'))->assertJson(['results' => []]);
    }

    public function test_same_phone_can_be_lent_to_another_attendee_in_assisted_wave(): void
    {
        $registrar = app(AttendeeRegistrar::class);
        ['attendee' => $owner, 'pin' => $ownerPin] = $registrar->register($this->election, 'Budi Santoso', null, $this->admin);
        ['attendee' => $elder, 'pin' => $elderPin] = $registrar->register($this->election, 'Mbah Karto', null, $this->admin);
        app(ElectionLifecycle::class)->start($this->election, $this->admin);
        $waves = app(WaveManager::class);
        $waves->open($this->election, WaveKind::Terbuka, 5, $this->admin);
        $payload = ['ballot' => $this->ballot->public_id, 'candidate' => $this->candidate->public_id];

        $this->post($this->url("/pin/{$owner->public_id}"), ['pin' => $ownerPin]);
        $this->post($this->url('/surat-suara'), $payload);
        $this->get($this->url('/selesai'))->assertSee('Sudah memilih');

        $waves->close($this->election, $this->admin);
        $waves->open($this->election, WaveKind::Bantuan, null, $this->admin);

        $this->get($this->url())->assertOk()->assertSee('Cari nama Anda');
        $this->getJson($this->url('/cari?q=mbah'))->assertJsonPath('results.0.name', 'Mbah Karto');
        $this->post($this->url("/pin/{$elder->public_id}"), ['pin' => $elderPin])
            ->assertRedirect(route('voter.ballot', $this->election->access_code));
        $this->post($this->url('/surat-suara'), $payload);

        $this->post($this->url("/pin/{$owner->public_id}"), ['pin' => $ownerPin])
            ->assertRedirect(route('voter.done', $this->election->access_code));
        $this->get($this->url('/selesai'))->assertSee('sudah memilih', false);

        $this->assertSame(2, Vote::query()->count());
    }

    public function test_resubmitting_after_vote_does_not_create_second_vote(): void
    {
        ['attendee' => $attendee, 'pin' => $pin] = app(AttendeeRegistrar::class)->register($this->election, 'Budi Santoso', null, $this->admin);
        app(ElectionLifecycle::class)->start($this->election, $this->admin);
        app(WaveManager::class)->open($this->election, WaveKind::Terbuka, 5, $this->admin);

        $this->post($this->url("/pin/{$attendee->public_id}"), ['pin' => $pin]);
        $payload = ['ballot' => $this->ballot->public_id, 'candidate' => $this->candidate->public_id];

        $this->post($this->url('/surat-suara'), $payload);
        $this->post($this->url('/surat-suara'), $payload);

        $this->assertSame(1, Vote::query()->count());
    }

    public function test_ballot_page_requires_verified_pin_session(): void
    {
        app(ElectionLifecycle::class)->start($this->election, $this->admin);
        app(WaveManager::class)->open($this->election, WaveKind::Terbuka, 5, $this->admin);

        $this->get($this->url('/surat-suara'))->assertRedirect(route('voter.show', $this->election->access_code));
        $this->post($this->url('/surat-suara'), [
            'ballot' => $this->ballot->public_id,
            'candidate' => $this->candidate->public_id,
        ])->assertRedirect(route('voter.show', $this->election->access_code));

        $this->assertSame(0, Vote::query()->count());
    }

    public function test_wrong_pin_shows_remaining_attempts(): void
    {
        ['attendee' => $attendee, 'pin' => $pin] = app(AttendeeRegistrar::class)->register($this->election, 'Budi Santoso', null, $this->admin);
        app(ElectionLifecycle::class)->start($this->election, $this->admin);
        app(WaveManager::class)->open($this->election, WaveKind::Terbuka, 5, $this->admin);

        $this->post($this->url("/pin/{$attendee->public_id}"), ['pin' => $pin === '5556' ? '5557' : '5556'])
            ->assertSessionHasErrors(['pin' => 'PIN salah. Sisa percobaan: 2.']);
    }

    public function test_unknown_access_code_returns_404(): void
    {
        $this->get('/v/salahkodeqr1')->assertNotFound();
    }
}
