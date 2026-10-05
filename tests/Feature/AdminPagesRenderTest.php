<?php

namespace Tests\Feature;

use App\Filament\Pages\ControlRoom;
use App\Filament\Pages\DoorDesk;
use App\Filament\Pages\ResultScreen;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Filament\Resources\Candidates\Pages\CreateCandidate;
use App\Filament\Resources\Elections\ElectionResource;
use App\Filament\Resources\Elections\Pages\CreateElection;
use App\Filament\Resources\Users\UserResource;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\User;
use App\Services\Voting\ElectionLifecycle;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $this->superAdmin->assignRole(User::ROLE_SUPER_ADMIN);
        $this->actingAs($this->superAdmin);
    }

    public function test_super_admin_can_open_every_page(): void
    {
        $election = Election::factory()->create();
        $ballot = Ballot::factory()->for($election)->create();
        $candidate = Candidate::factory()->for($ballot)->create();
        $user = User::factory()->create();

        $urls = [
            '/admin',
            ElectionResource::getUrl(),
            ElectionResource::getUrl('create'),
            ElectionResource::getUrl('edit', ['record' => $election]),
            CandidateResource::getUrl(),
            CandidateResource::getUrl('create'),
            CandidateResource::getUrl('edit', ['record' => $candidate]),
            UserResource::getUrl(),
            UserResource::getUrl('create'),
            UserResource::getUrl('edit', ['record' => $user]),
            AuditLogResource::getUrl(),
            DoorDesk::getUrl(),
            ControlRoom::getUrl(),
            ResultScreen::getUrl(),
            '/admin/profile',
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_create_election_and_candidate_through_forms(): void
    {
        Livewire::test(CreateElection::class)
            ->fillForm(['name' => 'Penjaringan Calon RW 2026', 'mode' => 'DADAKAN'])
            ->call('create')
            ->assertHasNoFormErrors();

        $election = Election::query()->firstOrFail();
        $ballot = Ballot::factory()->for($election)->create(['max_candidates' => 1]);

        Livewire::test(CreateCandidate::class)
            ->fillForm(['ballot_id' => $ballot->id, 'number' => 1, 'name' => 'Bapak Sutrisno', 'status' => 'AKTIF'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('Bapak Sutrisno', $ballot->candidates()->first()->name);

        Livewire::test(CreateCandidate::class)
            ->fillForm(['ballot_id' => $ballot->id, 'number' => 1, 'name' => 'Nomor Kembar', 'status' => 'AKTIF'])
            ->call('create')
            ->assertHasFormErrors(['number' => 'unique']);
    }

    public function test_candidates_cannot_be_added_after_election_starts(): void
    {
        $election = Election::factory()->create();
        $ballot = Ballot::factory()->for($election)->create();
        Candidate::factory()->for($ballot)->create(['number' => 1]);
        app(ElectionLifecycle::class)->markReady($election, $this->superAdmin);
        app(ElectionLifecycle::class)->start($election, $this->superAdmin);

        Livewire::test(CreateCandidate::class)
            ->fillForm(['ballot_id' => $ballot->id, 'number' => 2, 'name' => 'Penyusup', 'status' => 'AKTIF'])
            ->call('create')
            ->assertHasFormErrors(['ballot_id']);

        $this->assertSame(1, $ballot->candidates()->count());
    }
}
