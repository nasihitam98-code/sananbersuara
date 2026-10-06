<?php

namespace Tests\Feature;

use App\Enums\ElectionMode;
use App\Enums\StaffRole;
use App\Filament\Resources\Elections\ElectionResource;
use App\Filament\Support\Workspace;
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

    public function test_super_admin_chooses_mode_first(): void
    {
        $this->actingAs($this->superAdmin)->get('/admin')
            ->assertOk()
            ->assertSee('Mau mengurus pemilihan yang mana?')
            ->assertSee('Mode Dadakan')
            ->assertSee('Mode Resmi')
            ->assertSee(route('workspace.switch', 'dadakan'), false)
            ->assertDontSee('Meja Pintu')
            ->assertDontSee('Meja Izin');
    }

    public function test_dadakan_workspace_shows_current_stage_button_and_only_dadakan_menu(): void
    {
        $this->actingAs($this->superAdmin)->get(route('workspace.switch', 'dadakan'))->assertRedirect(url('/admin'));

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Mode Dadakan')
            ->assertSee('Ganti mode')
            ->assertSee('Penjaringan Calon RW')
            ->assertSee('Sudah siap. Petugas pintu sudah bisa mendata')
            ->assertSee('Buka pengaturan (Mulai Pemilihan)')
            ->assertSee('Surat suara:')
            ->assertSee('Klik tahap yang sudah lewat')
            ->assertSee(ElectionResource::getUrl('edit', ['record' => $this->election]), false)
            ->assertSee('Panduan langkah Mode Dadakan', false)
            ->assertSee('Meja Pintu')
            ->assertDontSee('Meja Izin')
            ->assertDontSee('Data Pemilih');
    }

    public function test_resmi_workspace_shows_only_resmi_menu(): void
    {
        $this->actingAs($this->superAdmin)
            ->withSession([Workspace::SESSION_KEY => ElectionMode::Resmi->value])
            ->get('/admin')
            ->assertSee('Mode Resmi')
            ->assertSee('Perangkat')
            ->assertSee('Data Pemilih')
            ->assertDontSee('Meja Pintu')
            ->assertDontSee('Penjaringan Calon RW');
    }

    public function test_door_staff_goes_straight_to_dadakan_and_cannot_switch_to_resmi(): void
    {
        $door = $this->makeUser(User::ROLE_STAFF, StaffRole::PetugasPintu);

        $this->actingAs($door)->get('/admin')
            ->assertOk()
            ->assertSee('Penjaringan Calon RW')
            ->assertSee('Buka Meja Pintu')
            ->assertSee('Meja Pintu: daftarkan yang hadir')
            ->assertDontSee('Ruang Kendali: buka voting')
            ->assertDontSee('Ganti mode');

        $this->actingAs($door)->get(route('workspace.switch', 'resmi'))->assertForbidden();
    }

    public function test_admin_rt_goes_straight_to_resmi(): void
    {
        $adminRt = $this->makeUser(User::ROLE_ADMIN_RT, unit: Unit::query()->firstOrFail());
        $adminRt->givePermissionTo([User::PERMISSION_MANAGE_VOTERS, User::PERMISSION_DESK]);
        Election::factory()->create(['name' => 'Pemilihan RT dan RW', 'mode' => ElectionMode::Resmi]);

        $this->actingAs($adminRt)->get('/admin')
            ->assertOk()
            ->assertSee('Pemilihan RT dan RW')
            ->assertDontSee('Penjaringan Calon RW')
            ->assertSee('Isi data pemilih per RT')
            ->assertDontSee('Meja Pintu')
            ->assertDontSee('Ganti mode');
    }

    public function test_public_portal_links_to_admin_and_lists_running_elections(): void
    {
        $this->get(route('public.index'))
            ->assertOk()
            ->assertSee('Masuk panitia dan pengurus')
            ->assertSee(url('/admin'), false)
            ->assertSee('Penjaringan Calon RW')
            ->assertSee('Segera dimulai');
    }
}
