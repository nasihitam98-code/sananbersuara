<?php

namespace Tests\Feature;

use App\Filament\Pages\GalleryPage;
use App\Filament\Resources\Candidates\Pages\CreateCandidate;
use App\Filament\Resources\Candidates\Pages\ListCandidates;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\GalleryPhoto;
use App\Models\Unit;
use App\Models\User;
use App\Services\Candidates\CandidateGallery;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\VotingException;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class GalleryTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Ballot $ballot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake(config('voting.photo.disk'));
        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->forceFill(['must_change_password' => false, 'has_email_authentication' => true])->save();
        $this->superAdmin->assignRole(User::ROLE_SUPER_ADMIN);

        $this->ballot = Ballot::factory()->for(Election::factory())->create();
    }

    private function addToGallery(string $name): GalleryPhoto
    {
        $path = UploadedFile::fake()->image($name, 800, 600)->store('unggahan-sementara', 'local');

        return app(CandidateGallery::class)->store($path, $name, $this->superAdmin);
    }

    public function test_upload_to_gallery_keeps_private_files_and_cleans_temporary_upload(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(GalleryPage::class)
            ->callAction('upload', ['photos' => [
                UploadedFile::fake()->image('01.jpg', 2000, 1500),
                UploadedFile::fake()->image('Ibu Sumiati.png', 800, 800),
            ]])
            ->assertHasNoFormErrors()
            ->assertNotified('2 foto masuk galeri.');

        $photos = GalleryPhoto::query()->orderBy('original_name')->get();
        $this->assertSame(['01.jpg', 'Ibu Sumiati.png'], $photos->pluck('original_name')->all());
        $this->assertSame(1600, $photos->first()->width, 'Diperkecil ke sisi terpanjang 1600 px');
        Storage::disk('local')->assertExists($photos->first()->path());
        Storage::disk('local')->assertExists($photos->first()->path('thumb'));
        $this->assertSame([], Storage::disk('local')->files('unggahan-sementara'));
        Storage::disk(config('voting.photo.disk'))->assertMissing($photos->first()->path());
    }

    public function test_preview_is_only_for_super_admin(): void
    {
        $photo = $this->addToGallery('01.jpg');

        $this->get($photo->previewUrl())->assertRedirect();

        $adminRt = User::factory()->create();
        $adminRt->forceFill(['unit_id' => Unit::query()->firstOrFail()->id])->save();
        $adminRt->assignRole(User::ROLE_ADMIN_RT);
        $this->actingAs($adminRt)->get($photo->previewUrl())->assertForbidden();

        $this->actingAs($this->superAdmin)->get($photo->previewUrl('full'))->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    public function test_pick_from_gallery_on_page_table_and_candidate_form(): void
    {
        $this->actingAs($this->superAdmin);
        $first = Candidate::factory()->for($this->ballot)->create(['number' => 1]);
        $second = Candidate::factory()->for($this->ballot)->create(['number' => 2]);
        $photoA = $this->addToGallery('a.jpg');
        $photoB = $this->addToGallery('b.jpg');

        Livewire::test(GalleryPage::class)
            ->callAction('assign', ['candidate_id' => $first->id], ['photo' => $photoA->public_id])
            ->assertNotified("Foto dipasang ke 01 {$first->name}.");

        // Grid pemilih foto yang sama dipakai di modal tombol Foto dan di form calon.
        Livewire::test(CreateCandidate::class)
            ->assertSee('Cari nama file')
            ->assertSee('a.jpg')
            ->assertSee('dipakai 01')
            ->assertSee('belum dipakai');

        Livewire::test(ListCandidates::class)
            ->callAction(TestAction::make('galleryPhoto')->table($second), ['gallery_photo_id' => $photoB->id])
            ->assertNotified("Foto dipasang ke 02 {$second->name}.");

        $this->assertSame($photoA->id, $first->fresh()->gallery_photo_id);
        $this->assertNotNull($first->fresh()->photo_key);
        $this->assertSame($photoB->id, $second->fresh()->gallery_photo_id);
        Storage::disk('local')->assertExists($photoA->path());

        Livewire::test(CreateCandidate::class)
            ->fillForm(['name' => 'Calon Ketiga', 'gallery_photo_pick' => $photoA->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $third = Candidate::query()->where('name', 'Calon Ketiga')->firstOrFail();
        $this->assertSame($photoA->id, $third->gallery_photo_id, 'Satu foto galeri boleh dipakai beberapa calon');
    }

    public function test_auto_assign_by_file_name_and_cleanup(): void
    {
        $this->actingAs($this->superAdmin);
        $first = Candidate::factory()->for($this->ballot)->create(['number' => 1, 'name' => 'Bapak Sutrisno']);
        $second = Candidate::factory()->for($this->ballot)->create(['number' => 2, 'name' => 'Ibu Sumiati']);
        $this->addToGallery('01.jpg');
        $this->addToGallery('sumiati.png');
        $leftover = $this->addToGallery('acara.jpg');

        Livewire::test(GalleryPage::class)
            ->callAction('autoAssign', ['ballot_id' => $this->ballot->id])
            ->assertNotified('2 foto dipasang.');

        $this->assertNotNull($first->fresh()->gallery_photo_id);
        $this->assertNotNull($second->fresh()->gallery_photo_id);
        $this->assertSame(1, GalleryPhoto::query()->whereDoesntHave('candidates')->count());

        Livewire::test(GalleryPage::class)
            ->callAction('deleteUnused')
            ->assertNotified('1 foto dihapus dari galeri.');

        $this->assertModelMissing($leftover);
        Storage::disk('local')->assertMissing($leftover->path());
        $this->assertSame(2, GalleryPhoto::query()->count());
    }

    public function test_deleting_used_photo_keeps_candidate_photo_and_started_election_is_locked(): void
    {
        $candidate = Candidate::factory()->for($this->ballot)->create(['number' => 1]);
        $photo = $this->addToGallery('01.jpg');
        $gallery = app(CandidateGallery::class);
        $gallery->assign($candidate, $photo, $this->superAdmin);
        $photoKey = $candidate->fresh()->photo_key;

        $gallery->delete($photo, $this->superAdmin);
        $this->assertSame($photoKey, $candidate->fresh()->photo_key, 'Foto calon tetap ada');
        $this->assertNull($candidate->fresh()->gallery_photo_id);

        $another = $this->addToGallery('02.jpg');
        app(ElectionLifecycle::class)->markReady($this->ballot->election, $this->superAdmin);
        app(ElectionLifecycle::class)->start($this->ballot->election, $this->superAdmin);

        $this->expectException(VotingException::class);
        $gallery->assign($candidate->fresh(), $another, $this->superAdmin);
    }

    public function test_gallery_page_is_super_admin_only(): void
    {
        $this->actingAs($this->superAdmin)->get(GalleryPage::getUrl())->assertOk()->assertSee('Galeri masih kosong');

        $committee = User::factory()->create();
        $committee->forceFill(['must_change_password' => false, 'has_email_authentication' => true])->save();
        $committee->assignRole(User::ROLE_STAFF);
        $this->actingAs($committee)->get(GalleryPage::getUrl())->assertForbidden();
    }
}
