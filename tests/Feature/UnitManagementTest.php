<?php

namespace Tests\Feature;

use App\Filament\Resources\Units\Pages\ManageUnits;
use App\Filament\Resources\Units\UnitResource;
use App\Models\AuditLog;
use App\Models\Unit;
use App\Models\User;
use App\Models\Voter;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UnitManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->forceFill(['must_change_password' => false, 'has_email_authentication' => true])->save();
        $this->superAdmin->assignRole(User::ROLE_SUPER_ADMIN);
    }

    public function test_add_rt_up_to_number_creates_only_missing_ones(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(ManageUnits::class)
            ->assertCanSeeTableRecords(Unit::query()->get())
            ->callAction('addUpTo', ['last' => 12])
            ->assertNotified('3 RT ditambahkan: RT 10, RT 11, RT 12.');

        $this->assertSame(12, Unit::query()->count());
        $this->assertSame(['10', '11', '12'], Unit::query()->where('sort', '>', 9)->orderBy('sort')->pluck('code')->all());
        $this->assertSame(3, AuditLog::query()->where('action', 'unit.created')->count());
    }

    public function test_create_single_rt_and_reject_duplicate_number(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(ManageUnits::class)
            ->callAction('create', ['code' => '15', 'name' => 'RT 15 Perumahan', 'sort' => 15])
            ->assertHasNoFormErrors();

        $this->assertTrue(Unit::query()->where('code', '15')->where('name', 'RT 15 Perumahan')->exists());

        Livewire::test(ManageUnits::class)
            ->callAction('create', ['code' => '03', 'name' => 'RT kembar', 'sort' => 99])
            ->assertHasFormErrors(['code' => 'unique']);
    }

    public function test_delete_only_unused_rt(): void
    {
        $this->actingAs($this->superAdmin);
        $empty = Unit::factory()->create(['code' => '20', 'name' => 'RT 20', 'sort' => 20]);
        $used = Unit::query()->where('code', '03')->firstOrFail();
        Voter::factory()->for($used)->create();

        Livewire::test(ManageUnits::class)
            ->callAction(TestAction::make('delete')->table($empty))
            ->assertNotified('RT dihapus.');
        $this->assertModelMissing($empty);

        Livewire::test(ManageUnits::class)
            ->callAction(TestAction::make('delete')->table($used))
            ->assertNotified('RT 03 tidak bisa dihapus karena sudah dipakai data lain (pemilih, calon, akun, atau pemilihan).');
        $this->assertModelExists($used);
    }

    public function test_only_super_admin_can_manage_rt(): void
    {
        $adminRt = User::factory()->create();
        $adminRt->forceFill(['must_change_password' => false, 'has_email_authentication' => true, 'unit_id' => Unit::query()->firstOrFail()->id])->save();
        $adminRt->assignRole(User::ROLE_ADMIN_RT);

        $this->actingAs($adminRt)->get(UnitResource::getUrl())->assertForbidden();
        $this->actingAs($this->superAdmin)->get(UnitResource::getUrl())->assertOk()->assertSee('RT 09');
    }
}
