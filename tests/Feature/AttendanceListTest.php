<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Enums\WaveKind;
use App\Filament\Pages\AttendanceList;
use App\Models\AuditLog;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\ElectionStaff;
use App\Models\User;
use App\Services\Exports\SpreadsheetSanitizer;
use App\Services\Voting\AttendeeRegistrar;
use App\Services\Voting\BallotBox;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\WaveManager;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceListTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Election $election;

    private Ballot $ballot;

    private Candidate $candidate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->superAdmin = $this->makeUser(User::ROLE_SUPER_ADMIN);
        $this->election = Election::factory()->create();
        $this->ballot = Ballot::factory()->for($this->election)->create();
        $this->candidate = Candidate::factory()->for($this->ballot)->create(['number' => 1]);
        app(ElectionLifecycle::class)->markReady($this->election, $this->superAdmin);
    }

    private function makeUser(string $role, ?StaffRole $staffRole = null, ?Election $election = null): User
    {
        $user = User::factory()->create();
        $user->forceFill(['must_change_password' => false, 'has_email_authentication' => true])->save();
        $user->assignRole($role);

        if ($staffRole !== null) {
            $staff = new ElectionStaff(['user_id' => $user->id, 'role' => $staffRole]);
            $staff->election()->associate($election ?? $this->election);
            $staff->save();
        }

        return $user;
    }

    public function test_committee_sees_every_attendee_with_voted_status(): void
    {
        $registrar = app(AttendeeRegistrar::class);
        ['attendee' => $voted, 'pin' => $pin] = $registrar->register($this->election, 'Budi Santoso', null, $this->superAdmin);
        ['attendee' => $waiting] = $registrar->register($this->election, 'Mbah Karto', null, $this->superAdmin);
        app(ElectionLifecycle::class)->start($this->election, $this->superAdmin);
        app(WaveManager::class)->open($this->election, WaveKind::Terbuka, 5, $this->superAdmin);
        $box = app(BallotBox::class);
        $box->cast($this->election, $voted, $box->verifyPin($this->election, $voted, $pin), $this->ballot, $this->candidate);

        $this->actingAs($this->makeUser(User::ROLE_STAFF, StaffRole::Panitia));

        Livewire::test(AttendanceList::class)
            ->assertCanSeeTableRecords([$voted, $waiting])
            ->assertSee('✓ Sudah memilih')
            ->assertSee('Belum memilih')
            ->filterTable('status', 'belum')
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$voted])
            ->resetTableFilters()
            ->searchTable('budi')
            ->assertCanSeeTableRecords([$voted])
            ->assertCanNotSeeTableRecords([$waiting])
            ->searchTable($waiting->displayNumber())
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$voted]);
    }

    public function test_door_staff_and_other_election_committee_cannot_see_the_list(): void
    {
        $this->actingAs($this->superAdmin)->get(AttendanceList::getUrl())->assertOk()->assertSee('Daftar Hadir');

        $door = $this->makeUser(User::ROLE_STAFF, StaffRole::PetugasPintu);
        $this->actingAs($door)->get(AttendanceList::getUrl())->assertForbidden();

        app(AttendeeRegistrar::class)->register($this->election, 'Budi Santoso', null, $this->superAdmin);
        $otherElection = Election::factory()->create();
        $otherCommittee = $this->makeUser(User::ROLE_STAFF, StaffRole::Panitia, $otherElection);

        $this->actingAs($otherCommittee);
        Livewire::test(AttendanceList::class, ['electionId' => $this->election->public_id])
            ->assertDontSee('Budi Santoso');
    }

    public function test_export_downloads_sanitized_excel_and_is_audited(): void
    {
        app(AttendeeRegistrar::class)->register($this->election, '=HYPERLINK("http://x")', null, $this->superAdmin);
        $this->actingAs($this->makeUser(User::ROLE_STAFF, StaffRole::Panitia));

        Livewire::test(AttendanceList::class)
            ->callAction(TestAction::make('export')->table())
            ->assertFileDownloaded('daftar-hadir-'.$this->election->public_id.'.xlsx');

        $this->assertTrue(AuditLog::query()->where('action', 'attendance.exported')->exists());
        $this->assertSame(["'=HYPERLINK(\"http://x\")", 'Budi', 3], SpreadsheetSanitizer::row(['=HYPERLINK("http://x")', 'Budi', 3]));
    }
}
