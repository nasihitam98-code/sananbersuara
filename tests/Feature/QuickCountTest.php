<?php

namespace Tests\Feature;

use App\Enums\BallotScope;
use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\WaveKind;
use App\Filament\Resources\Elections\Pages\CreateElection;
use App\Filament\Resources\Elections\Pages\EditElection;
use App\Models\AuditLog;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Unit;
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

    private function vote(string $name, ?Candidate $candidate = null): void
    {
        ['attendee' => $attendee, 'pin' => $pin] = app(AttendeeRegistrar::class)->register($this->election->fresh(), $name, null, $this->superAdmin);
        $election = $this->election->fresh();
        $box = app(BallotBox::class);
        $box->cast($election, $attendee->fresh(), $box->verifyPin($election, $attendee->fresh(), $pin), $this->ballot, $candidate ?? $this->first);
    }

    public function test_candidates_stay_in_number_order_and_the_leader_is_marked(): void
    {
        $this->election->forceFill(['settings' => ['show_public' => true, 'public_quick_count' => true]])->save();
        app(ElectionLifecycle::class)->start($this->election->fresh(), $this->superAdmin);
        app(WaveManager::class)->open($this->election->fresh(), WaveKind::Terbuka, null, $this->superAdmin);

        $second = $this->ballot->candidates()->where('number', 2)->firstOrFail();
        $this->vote('Santi', $second);
        $this->vote('Ojan', $second);
        $this->vote('Budi');

        // Nomor 02 unggul, tetapi kartu tetap urut 01 lalu 02 dengan warna per nomor.
        $this->get(route('public.index'))
            ->assertSeeInOrder(['Nomor 01', 'Calon Pertama', '33,3', 'Nomor 02', 'Calon Kedua', '66,7', 'Teratas'])
            ->assertSee('contender tone--red', false)
            ->assertSee('contender tone--amber', false);
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

    public function test_public_page_introduces_candidates_without_numbers_until_quick_count_takes_over(): void
    {
        $this->election->forceFill(['settings' => ['show_public' => true, 'public_quick_count' => true]])->save();

        // Siap: foto, nomor, dan nama calon tampil tanpa angka.
        $this->get(route('public.index'))
            ->assertOk()
            ->assertSee('Kenali calon')
            ->assertSee('data-section="calon"', false)
            ->assertSee('Lihat calon')
            ->assertSeeInOrder(['Calon Pertama', 'Calon Kedua'])
            ->assertSee('Quick count tampil di sini setelah voting dimulai.')
            ->assertDontSee('suara sah')
            ->assertDontSee('0 suara');

        // Voting berjalan dengan quick count: kartu quick count menggantikan bagian Calon.
        app(ElectionLifecycle::class)->start($this->election->fresh(), $this->superAdmin);
        $this->get(route('public.index'))
            ->assertSee('Quick count · hasil sementara')
            ->assertDontSee('Kenali calon');

        // Quick count dimatikan: bagian Calon kembali, tetap tanpa angka.
        $this->election->forceFill(['settings' => ['show_public' => true, 'public_quick_count' => false]])->save();
        $this->get(route('public.index'))
            ->assertSee('Kenali calon')
            ->assertSee('Perolehan suara tampil setelah hasil resmi diumumkan panitia.')
            ->assertDontSee('Quick count · hasil sementara');

        // Disembunyikan dari publik: calon tidak tampil.
        $this->election->forceFill(['settings' => ['show_public' => false]])->save();
        $this->get(route('public.index'))->assertDontSee('Kenali calon')->assertDontSee('Calon Pertama');
    }

    public function test_rt_picker_and_pin_show_from_two_rts(): void
    {
        $perRt = Ballot::factory()->for($this->election)->create(['title' => 'Ketua RT', 'scope' => BallotScope::PerRt]);

        foreach (['01', '02'] as $code) {
            Candidate::factory()->for($perRt)->create(['number' => 1, 'unit_id' => Unit::query()->where('code', $code)->value('id')]);
        }

        $this->get(route('public.index'))
            ->assertOk()
            ->assertSee('data-rt-picker', false)
            ->assertSee('data-rt-code="01"', false)
            ->assertSee('data-rt-code="02"', false)
            ->assertSee('data-rt-pinned', false)
            ->assertSee('carousel__page--2', false)
            // Tombol Layar penuh untuk laptop/TV di TPS.
            ->assertSee('data-fullscreen', false)
            ->assertSee('Layar penuh');
    }

    public function test_vision_and_mission_show_on_the_public_page_only_when_switched_on(): void
    {
        $this->first->update(['vision' => 'RW rukun dan aman.', 'mission' => "Ronda malam bergilir\nLaporan kas terbuka"]);

        $this->get(route('public.index'))->assertSee('Calon Pertama')->assertDontSee('RW rukun dan aman.');

        $this->actingAs($this->superAdmin);
        Livewire::test(EditElection::class, ['record' => $this->election->public_id])
            ->assertActionVisible('publicProfiles')
            ->callAction('publicProfiles')
            ->assertNotified('Visi & misi tampil di halaman publik.');

        $this->assertTrue(AuditLog::query()->where('action', 'election.public_profiles')->exists());

        $page = $this->get(route('public.index'))
            ->assertSee('RW rukun dan aman.')
            ->assertSee('<li>Laporan kas terbuka</li>', false);

        // Hanya calon yang mengisi visi & misi yang punya tombol Detail dan jendelanya.
        $this->assertSame(1, substr_count($page->getContent(), 'data-profile-open='));
        $this->assertSame(1, substr_count($page->getContent(), 'class="profile-dialog"'));

        // Selama voting, tombol Detail ikut ada di kartu quick count.
        $this->election->forceFill(['settings' => array_merge($this->election->fresh()->settings, ['public_quick_count' => true])])->save();
        app(ElectionLifecycle::class)->start($this->election->fresh(), $this->superAdmin);

        $page = $this->get(route('public.index'))->assertSee('Quick count · hasil sementara')->assertSee('RW rukun dan aman.');
        $this->assertSame(1, substr_count($page->getContent(), 'data-profile-open='));
    }

    public function test_public_page_switches_disappear_once_they_no_longer_matter(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(EditElection::class, ['record' => $this->election->public_id])
            ->assertActionVisible('publicListing')
            ->assertActionVisible('publicProfiles')
            ->assertActionVisible('quickCount');

        // Setelah ditutup quick count masih tampil (angka akhir) sampai diumumkan.
        app(ElectionLifecycle::class)->start($this->election->fresh(), $this->superAdmin);
        app(ElectionLifecycle::class)->close($this->election->fresh(), $this->superAdmin);
        Livewire::test(EditElection::class, ['record' => $this->election->public_id])
            ->assertActionVisible('quickCount')
            ->assertActionVisible('publicProfiles');

        $this->election->forceFill(['status' => ElectionStatus::Published])->save();
        Livewire::test(EditElection::class, ['record' => $this->election->public_id])
            ->assertActionVisible('publicListing')
            ->assertActionHidden('publicProfiles')
            ->assertActionHidden('quickCount');

        $this->election->forceFill(['status' => ElectionStatus::Cancelled])->save();
        Livewire::test(EditElection::class, ['record' => $this->election->public_id])
            ->assertActionHidden('publicListing')
            ->assertActionHidden('publicProfiles')
            ->assertActionHidden('quickCount');
    }

    public function test_quick_count_can_be_switched_on_while_creating_the_election(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(CreateElection::class)
            ->assertSee('Quick count di halaman publik')
            ->fillForm(['name' => 'Pemilihan RT RW', 'mode' => ElectionMode::Resmi->value, 'settings.public_quick_count' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = Election::query()->where('name', 'Pemilihan RT RW')->firstOrFail();
        $this->assertTrue((bool) $created->setting('public_quick_count'));
        $this->assertTrue($created->isPublic());
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
