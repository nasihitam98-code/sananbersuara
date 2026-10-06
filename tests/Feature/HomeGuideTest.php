<?php

namespace Tests\Feature;

use App\Enums\ElectionMode;
use App\Enums\StaffRole;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\ElectionStaff;
use App\Models\Unit;
use App\Models\User;
use App\Services\Voting\ElectionLifecycle;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeGuideTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Election $election;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->superAdmin = $this->makeUser(User::ROLE_SUPER_ADMIN);
        $this->election = Election::factory()->create(['name' => 'Penjaringan Calon RW']);
        Candidate::factory()->for(Ballot::factory()->for($this->election))->create(['number' => 1]);
        app(ElectionLifecycle::class)->markReady($this->election, $this->superAdmin);
    }

    private function makeUser(string $role, ?StaffRole $staffRole = null, ?Unit $unit = null): User
    {
        $user = User::factory()->create();
        $user->forceFill(['must_change_password' => false, 'has_email_authentication' => true, 'unit_id' => $unit?->id])->save();
        $user->assignRole($role);

        if ($staffRole !== null) {
            $staff = new ElectionStaff(['user_id' => $user->id, 'role' => $staffRole]);
            $staff->election()->associate($this->election);
            $staff->save();
        }

        return $user;
    }

    public function test_super_admin_home_shows_next_step_both_guides_and_public_link(): void
    {
        $this->actingAs($this->superAdmin)->get('/admin')
            ->assertOk()
            ->assertSee('Beranda')
            ->assertSee('Penjaringan Calon RW')
            ->assertSee('Petugas pintu sudah bisa mendata yang hadir')
            ->assertSee('Panduan Mode Dadakan')
            ->assertSee('Panduan Mode Resmi')
            ->assertSee(route('public.index'), false);
    }

    public function test_door_staff_only_sees_dadakan_steps_they_can_open(): void
    {
        $this->actingAs($this->makeUser(User::ROLE_STAFF, StaffRole::PetugasPintu))->get('/admin')
            ->assertOk()
            ->assertSee('Penjaringan Calon RW')
            ->assertSee('Buka Meja Pintu')
            ->assertSee('Meja Pintu: daftarkan yang hadir')
            ->assertDontSee('Ruang Kendali: buka voting')
            ->assertDontSee('Panduan Mode Resmi');
    }

    public function test_admin_rt_only_sees_resmi_guide(): void
    {
        $adminRt = $this->makeUser(User::ROLE_ADMIN_RT, unit: Unit::query()->firstOrFail());
        $adminRt->givePermissionTo([User::PERMISSION_MANAGE_VOTERS, User::PERMISSION_DESK]);
        Election::factory()->create(['name' => 'Pemilihan RT dan RW', 'mode' => ElectionMode::Resmi]);

        $this->actingAs($adminRt)->get('/admin')
            ->assertOk()
            ->assertSee('Pemilihan RT dan RW')
            ->assertDontSee('Penjaringan Calon RW')
            ->assertSee('Panduan Mode Resmi')
            ->assertSee('Isi data pemilih per RT')
            ->assertDontSee('Panduan Mode Dadakan');
    }

    public function test_public_portal_links_to_admin_and_lists_running_elections(): void
    {
        $this->get(route('public.index'))
            ->assertOk()
            ->assertSee('Masuk Panitia')
            ->assertSee(url('/admin'), false)
            ->assertSee('Penjaringan Calon RW')
            ->assertSee('Segera dimulai');
    }
}
