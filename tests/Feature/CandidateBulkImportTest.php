<?php

namespace Tests\Feature;

use App\Enums\BallotScope;
use App\Enums\ElectionStatus;
use App\Filament\Resources\Candidates\Pages\CreateCandidate;
use App\Filament\Resources\Candidates\Pages\ListCandidates;
use App\Models\AuditLog;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Unit;
use App\Models\User;
use App\Services\Candidates\CandidateBulkImporter;
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

class CandidateBulkImportTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Ballot $ballot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->forceFill(['must_change_password' => false, 'has_email_authentication' => true])->save();
        $this->superAdmin->assignRole(User::ROLE_SUPER_ADMIN);

        $this->ballot = Ballot::factory()->for(Election::factory())->create(['max_candidates' => 27]);
    }

    private function importer(): CandidateBulkImporter
    {
        return app(CandidateBulkImporter::class);
    }

    public function test_parses_plain_numbered_and_excel_lines(): void
    {
        $rows = $this->importer()->parse("Bapak Sutrisno\n\n5. Ibu Sumiati\n6\tBapak Ahmad Fauzi\n7 - H. Muhammad Nur\n  Ibu  Dewi  ");

        $this->assertSame([
            ['line' => 1, 'number' => null, 'name' => 'Bapak Sutrisno'],
            ['line' => 3, 'number' => 5, 'name' => 'Ibu Sumiati'],
            ['line' => 4, 'number' => 6, 'name' => 'Bapak Ahmad Fauzi'],
            ['line' => 5, 'number' => 7, 'name' => 'H. Muhammad Nur'],
            ['line' => 6, 'number' => null, 'name' => 'Ibu Dewi'],
        ], $rows);
    }

    public function test_import_continues_numbering_after_existing_candidates(): void
    {
        Candidate::factory()->for($this->ballot)->create(['number' => 1, 'name' => 'Sudah Ada']);

        $count = $this->importer()->import($this->ballot, null, "Ibu Sumiati\nBapak Ahmad\n10. Bapak Joko\nIbu Dewi", $this->superAdmin);

        $this->assertSame(4, $count);
        $this->assertSame(
            [1 => 'Sudah Ada', 2 => 'Ibu Sumiati', 3 => 'Bapak Ahmad', 10 => 'Bapak Joko', 11 => 'Ibu Dewi'],
            $this->ballot->candidates()->orderBy('number')->pluck('name', 'number')->all(),
        );
    }

    public function test_duplicate_number_or_over_limit_saves_nothing(): void
    {
        Candidate::factory()->for($this->ballot)->create(['number' => 1]);

        try {
            $this->importer()->import($this->ballot, null, "Ibu Sumiati\n1. Kembar", $this->superAdmin);
            $this->fail('Nomor kembar harus ditolak.');
        } catch (VotingException $exception) {
            $this->assertStringContainsString('nomor 1 sudah dipakai', $exception->getMessage());
        }

        $this->ballot->update(['max_candidates' => 2]);

        try {
            $this->importer()->import($this->ballot, null, "A Satu\nB Dua", $this->superAdmin);
            $this->fail('Melebihi batas harus ditolak.');
        } catch (VotingException $exception) {
            $this->assertStringContainsString('melebihi batas 2 calon', $exception->getMessage());
        }

        $this->assertSame(1, $this->ballot->candidates()->count());
    }

    public function test_duplicate_names_are_rejected_in_bulk_and_single_form(): void
    {
        Candidate::factory()->for($this->ballot)->create(['number' => 1, 'name' => 'Bapak Hartono']);

        try {
            $this->importer()->import($this->ballot, null, "Ibu Sumiati\nbapak  HARTONO\nIbu Sumiati", $this->superAdmin);
            $this->fail('Nama kembar harus ditolak.');
        } catch (VotingException $exception) {
            $this->assertStringContainsString('baris 2: nama "bapak HARTONO" sudah ada', $exception->getMessage());
            $this->assertStringContainsString('baris 3: nama "Ibu Sumiati" sudah ditulis di baris 1', $exception->getMessage());
        }

        $this->assertSame(1, $this->ballot->candidates()->count());

        $this->actingAs($this->superAdmin);
        Livewire::test(CreateCandidate::class)
            ->fillForm(['ballot_id' => $this->ballot->id, 'number' => 2, 'name' => 'Bapak Hartono'])
            ->call('create')
            ->assertHasFormErrors(['name']);

        Livewire::test(CreateCandidate::class)
            ->fillForm(['ballot_id' => $this->ballot->id, 'number' => 2, 'name' => 'Bapak Hartono (RT 03)'])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_per_rt_ballot_needs_rt_and_started_election_is_locked(): void
    {
        $perRt = Ballot::factory()->for(Election::factory())->create(['scope' => BallotScope::PerRt]);

        try {
            $this->importer()->import($perRt, null, 'Calon RT', $this->superAdmin);
            $this->fail('Surat suara per RT butuh RT.');
        } catch (VotingException $exception) {
            $this->assertStringContainsString('per RT', $exception->getMessage());
        }

        $this->assertSame(1, $this->importer()->import($perRt, Unit::query()->firstOrFail()->id, 'Calon RT', $this->superAdmin));

        Candidate::factory()->for($this->ballot)->create(['number' => 1]);
        app(ElectionLifecycle::class)->markReady($this->ballot->election, $this->superAdmin);
        app(ElectionLifecycle::class)->start($this->ballot->election, $this->superAdmin);

        $this->expectExceptionMessage('sudah dimulai');
        $this->importer()->import($this->ballot->fresh(), null, 'Penyusup', $this->superAdmin);
    }

    public function test_candidate_list_hides_candidates_of_cancelled_elections(): void
    {
        $this->actingAs($this->superAdmin);
        $active = Candidate::factory()->for($this->ballot)->create(['number' => 1, 'name' => 'Calon Aktif']);
        $cancelledElection = Election::factory()->create();
        $old = Candidate::factory()->for(Ballot::factory()->for($cancelledElection))->create(['number' => 1, 'name' => 'Calon Lama']);
        $cancelledElection->forceFill(['status' => ElectionStatus::Cancelled])->save();

        Livewire::test(ListCandidates::class)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$old]);
    }

    public function test_delete_from_list_removes_candidate_photo_and_is_audited(): void
    {
        Storage::fake('local');
        Storage::fake(config('voting.photo.disk'));
        $this->actingAs($this->superAdmin);
        $candidate = Candidate::factory()->for($this->ballot)->create(['number' => 1, 'name' => 'Salah Ketik']);
        $path = UploadedFile::fake()->image('1.jpg', 200, 200)->store('unggahan-sementara', 'local');
        $this->importer()->attachPhotos($this->ballot, null, [$path => '1.jpg'], $this->superAdmin);
        $this->assertNotEmpty(Storage::disk(config('voting.photo.disk'))->allFiles());

        Livewire::test(ListCandidates::class)
            ->assertActionVisible(TestAction::make('delete')->table($candidate))
            ->callAction(TestAction::make('delete')->table($candidate))
            ->assertNotified('Calon dihapus.');

        $this->assertModelMissing($candidate);
        $this->assertSame([], Storage::disk(config('voting.photo.disk'))->allFiles(), 'File foto ikut dihapus');
        $this->assertTrue(AuditLog::query()->where('action', 'candidate.deleted')->exists());
    }

    public function test_delete_is_hidden_after_election_starts(): void
    {
        $this->actingAs($this->superAdmin);
        $candidate = Candidate::factory()->for($this->ballot)->create(['number' => 1]);
        app(ElectionLifecycle::class)->markReady($this->ballot->election, $this->superAdmin);
        app(ElectionLifecycle::class)->start($this->ballot->election, $this->superAdmin);

        Livewire::test(ListCandidates::class)
            ->assertActionHidden(TestAction::make('delete')->table($candidate));
    }

    public function test_bulk_add_action_from_candidate_list(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(ListCandidates::class)
            ->callAction('bulkAdd', ['ballot_id' => $this->ballot->id, 'names' => "Bapak Sutrisno\nIbu Sumiati\nBapak Ahmad Fauzi"])
            ->assertHasNoFormErrors()
            ->assertNotified('3 calon ditambahkan.');

        $this->assertSame(3, $this->ballot->candidates()->count());
    }

    public function test_photos_without_number_are_matched_by_candidate_name(): void
    {
        Storage::fake('local');
        Storage::fake(config('voting.photo.disk'));
        $sutrisno = Candidate::factory()->for($this->ballot)->create(['number' => 1, 'name' => 'Bapak Sutrisno']);
        $sumiati = Candidate::factory()->for($this->ballot)->create(['number' => 2, 'name' => 'Ibu Sumiati']);
        Candidate::factory()->for($this->ballot)->create(['number' => 3, 'name' => 'Bapak Joko Susilo']);
        Candidate::factory()->for($this->ballot)->create(['number' => 4, 'name' => 'Ibu Joko Lestari']);

        $files = [];

        foreach (['IBU_SUMIATI.jpg', 'sutrisno.png', 'Joko.jpg', 'Orang Lain.jpg'] as $name) {
            $path = UploadedFile::fake()->image($name, 200, 200)->store('unggahan-sementara', 'local');
            $files[$path] = $name;
        }

        $result = $this->importer()->attachPhotos($this->ballot, null, $files, $this->superAdmin);

        $this->assertCount(2, $result['matched']);
        $this->assertNotNull($sumiati->fresh()->photo_key);
        $this->assertNotNull($sutrisno->fresh()->photo_key);
        $this->assertSame([
            'Joko.jpg (cocok dengan lebih dari satu calon; beri nomor di nama file)',
            'Orang Lain.jpg (tidak ada calon dengan nama ini)',
        ], $result['skipped']);
    }

    public function test_bulk_photo_action_keeps_original_file_names_for_matching(): void
    {
        Storage::fake('local');
        Storage::fake(config('voting.photo.disk'));
        $candidate = Candidate::factory()->for($this->ballot)->create(['number' => 3]);
        $this->actingAs($this->superAdmin);

        Livewire::test(ListCandidates::class)
            ->callAction('bulkPhotos', [
                'ballot_id' => $this->ballot->id,
                'photos' => [UploadedFile::fake()->image('03 - Bapak Ahmad.jpg', 300, 300)],
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('1 foto terpasang.');

        $this->assertNotNull($candidate->fresh()->photo_key);
    }

    public function test_photos_are_matched_by_number_in_file_name(): void
    {
        Storage::fake('local');
        Storage::fake(config('voting.photo.disk'));
        $first = Candidate::factory()->for($this->ballot)->create(['number' => 1]);
        $second = Candidate::factory()->for($this->ballot)->create(['number' => 2]);

        $files = [];

        foreach (['01.jpg', '2 - Ibu Sumiati.png', '99.jpg'] as $name) {
            $path = UploadedFile::fake()->image($name, 300, 200)->store('unggahan-sementara', 'local');
            $files[$path] = $name;
        }

        $result = $this->importer()->attachPhotos($this->ballot, null, $files, $this->superAdmin);

        $this->assertCount(2, $result['matched']);
        $this->assertSame(['99.jpg (tidak ada calon nomor 99)'], $result['skipped']);
        $this->assertNotNull($first->fresh()->photo_key);
        $this->assertNotNull($second->fresh()->photo_key);
        $this->assertSame([], Storage::disk('local')->files('unggahan-sementara'), 'File sementara dibersihkan');
    }
}
