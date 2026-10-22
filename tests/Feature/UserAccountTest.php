<?php

namespace Tests\Feature;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\StaffRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Workspace;
use App\Models\Election;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->superAdmin = User::factory()->create(['email' => 'ketua@contoh.test']);
        $this->superAdmin->forceFill(['must_change_password' => false, 'has_email_authentication' => true])->save();
        $this->superAdmin->assignRole(User::ROLE_SUPER_ADMIN);
    }

    public function test_new_admin_rt_gets_both_permissions_by_default_and_returns_to_the_list(): void
    {
        $this->actingAs($this->superAdmin);
        $rt01 = Unit::query()->where('code', '01')->firstOrFail();

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Admin RT 01', 'email' => 'rt01@contoh.test', 'password' => 'GladiRT01tes', 'role' => User::ROLE_ADMIN_RT, 'unit_id' => $rt01->id])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(UserResource::getUrl('index'));

        $created = User::query()->where('email', 'rt01@contoh.test')->sole();
        $this->assertTrue($created->hasPermissionTo(User::PERMISSION_MANAGE_VOTERS));
        $this->assertTrue($created->hasPermissionTo(User::PERMISSION_DESK));
    }

    public function test_assignment_in_a_cancelled_election_no_longer_offers_dadakan_mode(): void
    {
        $rt01 = Unit::query()->where('code', '01')->firstOrFail();
        $adminRt = User::factory()->create();
        $adminRt->forceFill(['unit_id' => $rt01->id, 'has_email_authentication' => true])->save();
        $adminRt->assignRole(User::ROLE_ADMIN_RT);

        $test = Election::factory()->create(['mode' => ElectionMode::Dadakan]);
        $test->staff()->create(['user_id' => $adminRt->id, 'role' => StaffRole::Panitia]);

        $this->assertSame([ElectionMode::Dadakan, ElectionMode::Resmi], Workspace::allowedModes($adminRt));

        $test->forceFill(['status' => ElectionStatus::Cancelled])->save();

        // Hanya Mode Resmi: Beranda langsung masuk tanpa pilihan mode.
        $this->assertSame([ElectionMode::Resmi], Workspace::allowedModes($adminRt));
    }

    public function test_admin_rt_needs_at_least_one_permission_and_an_unused_email(): void
    {
        $this->actingAs($this->superAdmin);
        $rt01 = Unit::query()->where('code', '01')->firstOrFail();

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Admin RT 01', 'email' => 'rt01@contoh.test', 'password' => 'GladiRT01tes', 'role' => User::ROLE_ADMIN_RT, 'unit_id' => $rt01->id, 'permissions' => []])
            ->call('create')
            ->assertHasFormErrors(['permissions' => 'required']);

        // Email Super Admin yang terisi otomatis oleh browser ditolak.
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Admin RT 01', 'email' => 'ketua@contoh.test', 'password' => 'GladiRT01tes', 'role' => User::ROLE_ADMIN_RT, 'unit_id' => $rt01->id])
            ->call('create')
            ->assertHasFormErrors(['email' => 'unique']);
    }
}
