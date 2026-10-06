<?php

namespace Tests\Feature;

use App\Enums\BallotScope;
use App\Enums\DeviceKind;
use App\Enums\ElectionMode;
use App\Filament\Pages\DeskPage;
use App\Filament\Pages\DevicesPage;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Device;
use App\Models\Election;
use App\Models\Permit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Voter;
use App\Services\Devices\DeviceManager;
use App\Services\Voting\ElectionLifecycle;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\TestCase;

class DeskPageTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $deskOfficer;

    private Election $election;

    private Voter $voter;

    private string $deskCookie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');

        $rt03 = Unit::query()->where('code', '03')->firstOrFail();
        $this->superAdmin = $this->makeUser(User::ROLE_SUPER_ADMIN);
        $this->deskOfficer = $this->makeUser(User::ROLE_ADMIN_RT, $rt03, [User::PERMISSION_DESK]);

        $this->election = Election::factory()->create(['mode' => ElectionMode::Resmi, 'settings' => ['max_booths_per_unit' => 1]]);
        $ballot = Ballot::factory()->for($this->election)->create(['title' => 'Ketua RW', 'scope' => BallotScope::SemuaRt]);
        Candidate::factory()->for($ballot)->create(['number' => 1]);
        $this->voter = Voter::factory()->for($rt03)->create(['name' => 'Budi Santoso']);

        $lifecycle = app(ElectionLifecycle::class);
        $lifecycle->markReady($this->election, $this->superAdmin);

        $manager = app(DeviceManager::class);
        $desk = Device::query()->where('kind', DeviceKind::Meja)->where('unit_id', $rt03->id)->firstOrFail();
        $pairedDesk = $manager->pair($manager->issueToken($desk, $this->superAdmin), 'Laptop Meja', Request::create('/'), DeviceKind::Meja);
        $this->deskCookie = $desk->public_id.'|'.$pairedDesk['secret'];

        $booth = Device::query()->where('kind', DeviceKind::Bilik)->where('unit_id', $rt03->id)->firstOrFail();
        $manager->pair($manager->issueToken($booth, $this->superAdmin), 'Laptop Bilik', Request::create('/'), DeviceKind::Bilik);

        $lifecycle->start($this->election, $this->superAdmin);
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

    public function test_desk_officer_on_desk_laptop_grants_permit(): void
    {
        $this->actingAs($this->deskOfficer);

        Livewire::withCookies([DeviceManager::COOKIE => $this->deskCookie])
            ->test(DeskPage::class)
            ->set('search', 'budi')
            ->assertSee('Budi Santoso')
            ->assertSee('Laptop Meja')
            ->callAction(TestAction::make('grant')->arguments(['voter' => $this->voter->public_id]))
            ->assertSet('lastAssignment.booth', 'Bilik 1');

        $this->assertSame(1, Permit::query()->whereNotNull('active_voter_id')->count());
    }

    public function test_same_officer_on_another_laptop_cannot_grant(): void
    {
        $this->actingAs($this->deskOfficer);

        Livewire::test(DeskPage::class)
            ->set('search', 'budi')
            ->assertSee('Bukan laptop Meja RT Anda')
            ->assertActionHidden(TestAction::make('grant')->arguments(['voter' => $this->voter->public_id]));

        $this->assertSame(0, Permit::query()->count());
    }

    public function test_pages_are_restricted_by_role(): void
    {
        $this->actingAs($this->deskOfficer)->get(DeskPage::getUrl())->assertOk();
        $this->actingAs($this->deskOfficer)->get(DevicesPage::getUrl())->assertForbidden();
        $this->actingAs($this->superAdmin)->get(DevicesPage::getUrl())->assertOk()->assertSee('RT03-MEJA');
        $this->actingAs($this->superAdmin)->get(DeskPage::getUrl())->assertForbidden();
    }
}
