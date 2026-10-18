<?php

namespace Tests\Feature;

use App\Enums\WaveKind;
use App\Filament\Resources\Elections\Pages\EditElection;
use App\Models\AuditLog;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\User;
use App\Services\Voting\AttendeeRegistrar;
use App\Services\Voting\BallotBox;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\WaveManager;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class QuickCountTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Election $election;

    private Ballot $ballot;

    private Candidate $first;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->forceFill(['must_change_password' => false, 'has_email_authentication' => true])->save();
        $this->superAdmin->assignRole(User::ROLE_SUPER_ADMIN);

        $this->election = Election::factory()->create(['name' => 'Pemilihan Uji', 'settings' => ['show_public' => true]]);
        $this->ballot = Ballot::factory()->for($this->election)->create(['title' => 'Calon Ketua RW']);
        $this->first = Candidate::factory()->for($this->ballot)->create(['number' => 1, 'name' => 'Calon Pertama']);
        Candidate::factory()->for($this->ballot)->create(['number' => 2, 'name' => 'Calon Kedua']);
        app(ElectionLifecycle::class)->markReady($this->election, $this->superAdmin);
    }

    private function vote(string $name): void
    {
        ['attendee' => $attendee, 'pin' => $pin] = app(AttendeeRegistrar::class)->register($this->election->fresh(), $name, null, $this->superAdmin);
        $election = $this->election->fresh();
        $box = app(BallotBox::class);
        $box->cast($election, $attendee->fresh(), $box->verifyPin($election, $attendee->fresh(), $pin), $this->ballot, $this->first);
    }

    public function test_quick_count_is_off_until_super_admin_turns_it_on(): void
    {
        // Daftar Persiapan (sebelum dimulai) menunjukkan letak saklarnya.
        $this->actingAs($this->superAdmin);
        Livewire::test(EditElection::class, ['record' => $this->election->public_id])
            ->assertSee('Halaman publik & quick count (opsional)')
            ->assertSee('quick count mati');

        app(ElectionLifecycle::class)->start($this->election->fresh(), $this->superAdmin);
        app(WaveManager::class)->open($this->election->fresh(), WaveKind::Terbuka, 30, $this->superAdmin);
        $this->vote('Santi');

        $this->get(route('public.index'))->assertOk()->assertDontSee('Quick count · hasil sementara');

        Livewire::test(EditElection::class, ['record' => $this->election->public_id])
            ->assertActionVisible('quickCount')
            ->callAction('quickCount')
            ->assertNotified('Quick count dinyalakan.');

        $this->assertTrue((bool) $this->election->fresh()->setting('public_quick_count'));
        $this->assertTrue(AuditLog::query()->where('action', 'election.quick_count')->exists());

        $this->get(route('public.index'))
            ->assertOk()
            ->assertSee('Quick count · hasil sementara')
            ->assertSee('Belum resmi')
            ->assertSee('Sementara')
            ->assertSee('Calon Pertama')
            ->assertSee('1 suara')
            ->assertSee('Lihat quick count');
    }

    public function test_numbers_refresh_every_ten_minutes_and_final_numbers_show_once_closed(): void
    {
        $this->election->forceFill(['settings' => ['show_public' => true, 'public_quick_count' => true]])->save();
        app(ElectionLifecycle::class)->start($this->election->fresh(), $this->superAdmin);
        app(WaveManager::class)->open($this->election->fresh(), WaveKind::Terbuka, null, $this->superAdmin);

        $this->vote('Santi');
        $this->get(route('public.index'))->assertSee('1 suara');

        // Suara baru tidak langsung tampil (agar pilihan orang yang baru keluar bilik tidak bisa ditebak).
        $this->vote('Ojan');
        $this->get(route('public.index'))->assertSee('1 suara')->assertDontSee('2 suara');

        $this->travel(10)->minutes();
        $this->get(route('public.index'))->assertSee('2 suara');

        // Ditutup: angka akhir langsung tampil, masih ditandai sementara sampai diumumkan.
        $this->vote('Budi');
        app(ElectionLifecycle::class)->close($this->election->fresh(), $this->superAdmin);
        $this->get(route('public.index'))->assertSee('Voting selesai')->assertSee('3 suara')->assertSee('Sementara');
    }

    public function test_hidden_elections_never_show_a_quick_count(): void
    {
        $this->election->forceFill(['settings' => ['show_public' => false, 'public_quick_count' => true]])->save();
        app(ElectionLifecycle::class)->start($this->election->fresh(), $this->superAdmin);
        app(WaveManager::class)->open($this->election->fresh(), WaveKind::Terbuka, null, $this->superAdmin);
        $this->vote('Santi');

        $this->get(route('public.index'))->assertOk()->assertDontSee('Calon Pertama');

        // Saklarnya pun tidak muncul selama pemilihan tidak tampil di halaman publik.
        $this->actingAs($this->superAdmin);
        Livewire::test(EditElection::class, ['record' => $this->election->public_id])->assertActionHidden('quickCount');
    }
}
