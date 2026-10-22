<?php

namespace Tests\Feature;

use App\Enums\BallotScope;
use App\Enums\ElectionMode;
use App\Filament\Resources\Voters\Pages\CreateVoter;
use App\Filament\Resources\Voters\Pages\ListVoters;
use App\Filament\Resources\Voters\VoterResource;
use App\Models\Ballot;
use App\Models\BallotVoter;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Unit;
use App\Models\User;
use App\Models\Voter;
use App\Services\Voters\DuplicateNameWarning;
use App\Services\Voters\VoterImport;
use App\Services\Voters\VoterRegistry;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\VotingException;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VoterManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Unit $rt03;

    private Unit $rt04;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->superAdmin = $this->makeUser(User::ROLE_SUPER_ADMIN);
        $this->rt03 = Unit::query()->where('code', '03')->firstOrFail();
        $this->rt04 = Unit::query()->where('code', '04')->firstOrFail();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function makeUser(string $role, ?Unit $unit = null, array $permissions = []): User
    {
        $user = User::factory()->create();
        $user->forceFill(['has_email_authentication' => true, 'unit_id' => $unit?->id])->save();
        $user->assignRole($role);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function adminRt(Unit $unit): User
    {
        return $this->makeUser(User::ROLE_ADMIN_RT, $unit, [User::PERMISSION_MANAGE_VOTERS]);
    }

    public function test_name_and_rt_are_enough_and_emergency_section_only_shows_while_live(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(CreateVoter::class)
            ->assertDontSee('Tambah darurat saat pemilihan berlangsung')
            ->assertDontSee('Data tambahan')
            ->fillForm(['name' => 'Ojan', 'unit_id' => $this->rt03->id])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(VoterResource::getUrl('index'));

        $this->assertSame('', Voter::query()->where('name', 'Ojan')->sole()->address);
    }

    public function test_voters_are_listed_per_rt_and_new_ones_go_to_the_open_rt(): void
    {
        $this->actingAs($this->superAdmin);
        Voter::factory()->for($this->rt03)->create(['name' => 'Warga Tiga']);
        Voter::factory()->for($this->rt04)->create(['name' => 'Warga Empat']);

        // Tab per RT (tidak tercampur); tombol Tambah mengikuti RT yang dibuka.
        Livewire::test(ListVoters::class)
            ->set('activeTab', '03')
            ->assertSee('Warga Tiga')
            ->assertDontSee('Warga Empat')
            ->assertActionHasLabel('create', 'Tambah pemilih RT 03')
            ->assertActionHasUrl('create', VoterResource::getUrl('create', ['rt' => '03']))
            ->callAction('bulkAdd', ['names' => 'Tempel Satu'])
            ->assertNotified('1 pemilih ditambahkan.');

        $this->assertSame($this->rt03->id, Voter::query()->where('name', 'Tempel Satu')->sole()->unit_id);

        $this->get(VoterResource::getUrl('create', ['rt' => '04']))->assertOk();
        Livewire::withQueryParams(['rt' => '04'])->test(CreateVoter::class)->assertFormSet(['unit_id' => $this->rt04->id]);
    }

    public function test_many_voters_can_be_pasted_with_their_rt(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(ListVoters::class)
            ->callAction('bulkAdd', ['unit_id' => $this->rt03->id, 'names' => "Tes Satu\nTes Dua | RT 04\nTes Tiga | 3 | Jl. Mawar 1"])
            ->assertNotified('3 pemilih ditambahkan.');

        $this->assertSame(['Tes Dua' => '04', 'Tes Satu' => '03', 'Tes Tiga' => '03'], Voter::query()->with('unit')->orderBy('name')->get()->mapWithKeys(fn (Voter $voter): array => [$voter->name => $voter->unit->code])->all());

        // Satu baris salah (RT tidak dikenal): tidak ada yang disimpan.
        Livewire::test(ListVoters::class)->callAction('bulkAdd', ['unit_id' => null, 'names' => "Tes Empat | RT 03\nTes Lima | RT 77"]);
        $this->assertSame(3, Voter::query()->count());

        // Nama sama perlu dicentang dulu.
        Livewire::test(ListVoters::class)->callAction('bulkAdd', ['names' => 'Tes Satu | RT 03']);
        $this->assertSame(3, Voter::query()->count());
        Livewire::test(ListVoters::class)->callAction('bulkAdd', ['names' => 'Tes Satu | RT 03', 'confirm_same_names' => true]);
        $this->assertSame(4, Voter::query()->count());

        // Admin RT: semua masuk RT sendiri; baris RT lain ditolak.
        $this->actingAs($this->adminRt($this->rt03));
        Livewire::test(ListVoters::class)->callAction('bulkAdd', ['names' => 'Warga RT Tiga']);
        $this->assertSame('03', Voter::query()->where('name', 'Warga RT Tiga')->sole()->unit->code);
        Livewire::test(ListVoters::class)->callAction('bulkAdd', ['names' => 'Penyusup | RT 04']);
        $this->assertFalse(Voter::query()->where('name', 'Penyusup')->exists());
    }

    public function test_nik_is_never_stored_in_full(): void
    {
        $voter = app(VoterRegistry::class)->create(['name' => 'Budi Santoso', 'address' => 'Jl. Mawar 12'], $this->rt03->id, $this->superAdmin, '3507011201700001');

        $this->assertSame('0001', $voter->nik_last4);
        $this->assertSame('************0001', $voter->maskedNik());
        $this->assertStringNotContainsString('3507011201700001', (string) json_encode($voter->fresh()->getAttributes()));
        $this->assertMatchesRegularExpression('/^VTR-\d{6}$/', $voter->fresh()->voter_number);
    }

    public function test_same_nik_in_another_rt_is_rejected(): void
    {
        $registry = app(VoterRegistry::class);
        $registry->create(['name' => 'Budi Santoso', 'address' => 'Jl. Mawar 12'], $this->rt03->id, $this->superAdmin, '3507011201700001');

        $this->expectExceptionObject(VotingException::invalidState('Warga ini terdaftar di RT lain, memilih di RT asalnya.'));
        $registry->create(['name' => 'Budi S', 'address' => 'Jl. Melati 1'], $this->rt04->id, $this->superAdmin, '3507011201700001');
    }

    public function test_same_name_and_birth_date_in_another_rt_is_rejected(): void
    {
        $registry = app(VoterRegistry::class);
        $registry->create(['name' => 'Siti Aminah', 'address' => 'Jl. A', 'birth_date' => '1980-05-01'], $this->rt03->id, $this->superAdmin);

        $this->expectException(VotingException::class);
        $registry->create(['name' => 'siti  aminah', 'address' => 'Jl. B', 'birth_date' => '1980-05-01'], $this->rt04->id, $this->superAdmin);
    }

    public function test_same_name_only_needs_confirmation(): void
    {
        $registry = app(VoterRegistry::class);
        $registry->create(['name' => 'Ahmad Fauzi', 'address' => 'Jl. A'], $this->rt03->id, $this->superAdmin);

        try {
            $registry->create(['name' => 'Ahmad Fauzi', 'address' => 'Jl. B'], $this->rt04->id, $this->superAdmin);
            $this->fail('Nama sama seharusnya butuh konfirmasi.');
        } catch (DuplicateNameWarning $warning) {
            $this->assertCount(1, $warning->similar);
        }

        $registry->create(['name' => 'Ahmad Fauzi', 'address' => 'Jl. B'], $this->rt04->id, $this->superAdmin, confirmedDifferentPerson: true);
        $this->assertSame(2, Voter::query()->count());
    }

    public function test_admin_rt_cannot_create_or_see_voters_of_another_rt(): void
    {
        $adminRt03 = $this->adminRt($this->rt03);
        $foreign = Voter::factory()->for($this->rt04)->create(['name' => 'Warga RT Empat']);
        Voter::factory()->for($this->rt03)->create(['name' => 'Warga RT Tiga']);

        $this->actingAs($adminRt03)
            ->get(VoterResource::getUrl())
            ->assertOk()
            ->assertSee('Warga RT Tiga')
            ->assertDontSee('Warga RT Empat');

        $this->actingAs($adminRt03)->get(VoterResource::getUrl('edit', ['record' => $foreign]))->assertNotFound();

        $this->expectException(HttpException::class);
        app(VoterRegistry::class)->create(['name' => 'Penyusup', 'address' => 'Jl. X'], $this->rt04->id, $adminRt03);
    }

    public function test_desk_only_admin_rt_cannot_manage_voters(): void
    {
        $deskOnly = $this->makeUser(User::ROLE_ADMIN_RT, $this->rt03, [User::PERMISSION_DESK]);

        $this->actingAs($deskOnly)->get(VoterResource::getUrl())->assertForbidden();
    }

    public function test_voter_with_history_cannot_be_deleted(): void
    {
        $voter = Voter::factory()->for($this->rt03)->create();
        $election = Election::factory()->create(['mode' => ElectionMode::Resmi]);
        $ballot = Ballot::factory()->for($election)->create(['scope' => BallotScope::SemuaRt]);
        $entry = new BallotVoter;
        $entry->forceFill(['ballot_id' => $ballot->id, 'voter_id' => $voter->id, 'unit_id' => $voter->unit_id])->save();

        $this->expectException(VotingException::class);
        app(VoterRegistry::class)->delete($voter, $this->superAdmin);
    }

    public function test_import_previews_errors_without_saving_then_imports_valid_rows(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($csv, implode("\n", [
            'nama;rt;alamat;jenis_kelamin;tanggal_lahir;nik;no_hp',
            'Budi Santoso;03;Jl. Mawar 12;L;31-12-1970;3507011201700001;081234567890',
            ';03;Jl. Kosong;;;;',
            'Warga RT Empat;04;Jl. Melati;P;;;',
            'Siti Aminah;3;Jl. Kenanga 5;P;01-05-1980;;',
            '=HYPERLINK("x");03;Jl. Rumus;L;;;',
        ]));

        $adminRt03 = $this->adminRt($this->rt03);
        $import = app(VoterImport::class);
        $rows = $import->preview($csv, 'csv', $adminRt03);

        $this->assertSame(0, Voter::query()->count());
        $this->assertCount(5, $rows);
        $this->assertSame([], $rows[0]['errors']);
        $this->assertContains('Nama kosong/terlalu pendek', $rows[1]['errors']);
        $this->assertContains('Anda hanya boleh mengimpor pemilih RT sendiri', $rows[2]['errors']);
        $this->assertSame([], $rows[3]['errors']);
        $this->assertStringStartsNotWith('=', $rows[4]['name']);

        $result = $import->import($rows, $adminRt03, false);

        $this->assertSame(3, $result['imported']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(3, Voter::query()->where('unit_id', $this->rt03->id)->count());
        $this->assertSame('1970-12-31', Voter::query()->where('name', 'Budi Santoso')->first()->birth_date->format('Y-m-d'));
    }

    public function test_snapshot_follows_ballot_scope(): void
    {
        foreach ([$this->rt03, $this->rt04] as $unit) {
            Voter::factory()->count(3)->for($unit)->create();
        }

        Voter::factory()->for($this->rt03)->create(['is_active' => false]);

        $election = Election::factory()->create(['mode' => ElectionMode::Resmi]);
        $rt = Ballot::factory()->for($election)->create(['title' => 'Ketua RT', 'scope' => BallotScope::PerRt]);
        $rw = Ballot::factory()->for($election)->create(['title' => 'Ketua RW', 'scope' => BallotScope::SemuaRt]);
        $onlyRt03 = Ballot::factory()->for($election)->create(['title' => 'Ulang RT 03', 'scope' => BallotScope::RtTertentu]);
        $onlyRt03->units()->attach($this->rt03);

        Candidate::factory()->for($rt)->create(['number' => 1, 'unit_id' => $this->rt03->id]);
        Candidate::factory()->for($rw)->create(['number' => 1]);
        Candidate::factory()->for($onlyRt03)->create(['number' => 1]);

        $lifecycle = app(ElectionLifecycle::class);
        $lifecycle->markReady($election, $this->superAdmin);
        $lifecycle->start($election, $this->superAdmin);

        $this->assertSame(3, $rt->voterEntries()->count(), 'Per RT: hanya RT yang punya calon');
        $this->assertSame(6, $rw->voterEntries()->count(), 'Semua RT: semua pemilih aktif');
        $this->assertSame(3, $onlyRt03->voterEntries()->count(), 'RT tertentu: hanya RT 03');
    }

    public function test_adding_voter_during_live_election_requires_reason_and_enters_snapshot(): void
    {
        Voter::factory()->for($this->rt03)->create();
        $election = Election::factory()->create(['mode' => ElectionMode::Resmi]);
        $rw = Ballot::factory()->for($election)->create(['scope' => BallotScope::SemuaRt]);
        Candidate::factory()->for($rw)->create(['number' => 1]);
        app(ElectionLifecycle::class)->markReady($election, $this->superAdmin);
        app(ElectionLifecycle::class)->start($election, $this->superAdmin);

        $registry = app(VoterRegistry::class);

        try {
            $registry->create(['name' => 'Warga Baru', 'address' => 'Jl. Baru'], $this->rt03->id, $this->superAdmin);
            $this->fail('Tanpa alasan darurat seharusnya ditolak.');
        } catch (VotingException) {
            $this->assertSame(1, Voter::query()->count());
        }

        $voter = $registry->create(['name' => 'Warga Baru', 'address' => 'Jl. Baru'], $this->rt03->id, $this->superAdmin, emergencyReason: 'BELUM_ADA_DI_DATA_AWAL');

        $this->assertTrue($voter->added_during_live);
        $this->assertTrue($rw->voterEntries()->where('voter_id', $voter->id)->where('added_during_live', true)->exists());
    }
}
