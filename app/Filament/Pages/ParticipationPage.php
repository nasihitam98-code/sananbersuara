<?php

namespace App\Filament\Pages;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\VoteStatus;
use App\Filament\Support\Workspace;
use App\Models\Attendee;
use App\Models\BallotVoter;
use App\Models\Election;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vote;
use App\Models\Voter;
use App\Services\Voting\ResultsCalculator;
use App\Services\Voting\RoundResolver;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Status & Partisipasi Mode Resmi (bagian 9 dan 11): jumlah dan persen sudah memilih per surat suara
 * per RT, plus daftar yang belum memilih. Tidak pernah menampilkan angka per kandidat.
 * Super Admin: semua RT. Admin RT: RT sendiri.
 */
class ParticipationPage extends Page
{
    protected string $view = 'filament.pages.participation-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Hari H';

    protected static ?string $navigationLabel = 'Partisipasi';

    protected static ?string $title = 'Partisipasi Pemilihan';

    protected static ?string $slug = 'partisipasi';

    protected static ?int $navigationSort = 3;

    /**
     * Petugas Meja Izin melihat belum/sudah memilih langsung di Meja Izin, jadi menu ini tidak ditampilkan untuk
     * mereka (halamannya tetap bisa dibuka). Super Admin dan Admin RT tanpa izin meja tetap melihatnya.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return Workspace::shows(ElectionMode::Resmi) && ! DeskPage::canAccess();
    }

    public ?int $unitFilter = null;

    public string $notVotedSearch = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->isSuperAdmin() || $user->isAdminRt());
    }

    public function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function election(): ?Election
    {
        return Election::query()
            ->where('mode', ElectionMode::Resmi)
            ->whereIn('status', [ElectionStatus::Berlangsung, ElectionStatus::Paused, ElectionStatus::Ditutup, ElectionStatus::Verifikasi, ElectionStatus::Published, ElectionStatus::Unpublished])
            ->latest()
            ->first();
    }

    /**
     * RT yang boleh dilihat pengguna ini.
     *
     * @return Collection<int, Unit>
     */
    public function units(): Collection
    {
        return $this->user()->isSuperAdmin()
            ? Unit::query()->orderBy('sort')->get()
            : Unit::query()->whereKey($this->user()->unit_id)->get();
    }

    /**
     * @return array<int, array{title: string, total: array<string, mixed>, units: array<int, array<string, mixed>>}>
     */
    public function table(): array
    {
        $election = $this->election();

        if ($election === null) {
            return [];
        }

        $round = $election->currentRound();
        $calculator = app(ResultsCalculator::class);
        $units = $this->units();

        return $election->ballots()->get()->map(function ($ballot) use ($round, $calculator, $units): array {
            $perUnit = $units
                ->map(fn (Unit $unit): array => ['unit' => $unit->name, 'unit_id' => $unit->id] + $calculator->ballotParticipation($ballot, app(RoundResolver::class)->roundFor($ballot->election, $ballot, $unit->id) ?? $round, $unit->id))
                ->filter(fn (array $row): bool => $row['eligible'] > 0)
                ->values()
                ->all();

            return [
                'title' => $ballot->title,
                'total' => $this->user()->isSuperAdmin() ? $calculator->ballotParticipation($ballot, app(RoundResolver::class)->roundFor($ballot->election, $ballot, null) ?? $round) : null,
                'units' => $perUnit,
            ];
        })->all();
    }

    /** Batas nama yang ditampilkan sekaligus (Semua RT); saring per RT atau nama untuk melihat sisanya. */
    public const NOT_VOTED_LIMIT = 1000;

    /** Daftar nama: 'belum' (belum menyelesaikan semua surat suara) atau 'sudah' (sudah semua). */
    public string $voterTab = 'belum';

    /** Klik kartu RT (Super Admin): daftar nama langsung disaring ke RT itu. */
    public function showUnit(?int $unitId): void
    {
        abort_unless($this->user()->isSuperAdmin(), 403);

        $this->unitFilter = $unitId;
    }

    /**
     * Jumlah pemilih per daftar (sesuai saringan RT, tanpa saringan nama).
     *
     * @return array{belum: int, sudah: int}
     */
    public function voterCounts(): array
    {
        $ids = $this->voterIdsByStatus();

        return ['belum' => count($ids['belum']), 'sudah' => count($ids['sudah'])];
    }

    /**
     * Nama pemilih pada tab yang dipilih (tanpa pilihan kandidat dan tanpa jam memilih). Super Admin tanpa
     * saringan RT: semua RT.
     *
     * @return Collection<int, Voter>
     */
    public function voterList(): Collection
    {
        $ids = $this->voterIdsByStatus()[$this->voterTab === 'sudah' ? 'sudah' : 'belum'];

        if ($ids === []) {
            return collect();
        }

        return Voter::query()
            ->with('unit')
            ->whereIn('id', $ids)
            ->when(mb_strlen(trim($this->notVotedSearch)) >= 2, fn ($query) => $query->where('name_search', 'like', '%'.addcslashes(Attendee::normalizeForSearch($this->notVotedSearch), '%_\\').'%'))
            ->orderBy('name')
            ->limit(self::NOT_VOTED_LIMIT)
            ->get();
    }

    /**
     * Pemilih berhak dibagi dua: belum menyelesaikan semua surat suara berhaknya, dan sudah.
     *
     * @return array{belum: array<int, int>, sudah: array<int, int>}
     */
    private function voterIdsByStatus(): array
    {
        $election = $this->election();
        $unitId = $this->user()->isSuperAdmin() ? $this->unitFilter : $this->user()->unit_id;

        if ($election === null || (! $this->user()->isSuperAdmin() && $unitId === null)) {
            return ['belum' => [], 'sudah' => []];
        }

        $round = $election->currentRound();
        $ballotIds = $election->ballots()->pluck('id');

        $eligible = BallotVoter::query()->whereIn('ballot_id', $ballotIds)->when($unitId !== null, fn ($query) => $query->where('unit_id', $unitId))->whereNull('revoked_at')
            ->get(['voter_id', 'ballot_id'])->groupBy('voter_id');
        $voted = Vote::query()->whereIn('ballot_id', $ballotIds)->where('round_id', $round?->id)->where('status', VoteStatus::Sah)
            ->whereIn('voter_id', $eligible->keys())->get(['voter_id', 'ballot_id'])->groupBy('voter_id');

        [$pending, $done] = $eligible->partition(fn ($rows, $voterId): bool => $rows->count() > $voted->get($voterId, collect())->count());

        return ['belum' => $pending->keys()->all(), 'sudah' => $done->keys()->all()];
    }
}
