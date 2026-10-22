<?php

namespace App\Filament\Pages\Concerns;

use App\Enums\VoteStatus;
use App\Models\Attendee;
use App\Models\BallotVoter;
use App\Models\Election;
use App\Models\User;
use App\Models\Vote;
use App\Models\Voter;
use Illuminate\Support\Collection;

/**
 * Daftar nama belum/sudah memilih (tanpa pilihan kandidat dan tanpa jam memilih), dipakai Monitoring dan Partisipasi.
 * Super Admin: semua RT atau satu RT (klik kartu RT); lainnya: RT sendiri. View: filament.pages.partials.voter-progress.
 */
trait ShowsVoterProgress
{
    /** Batas nama yang ditampilkan sekaligus (Semua RT); saring per RT atau nama untuk melihat sisanya. */
    public const NOT_VOTED_LIMIT = 1000;

    public ?int $unitFilter = null;

    public string $notVotedSearch = '';

    /** Daftar nama: 'belum' (belum menyelesaikan semua surat suara) atau 'sudah' (sudah semua). */
    public string $voterTab = 'belum';

    abstract public function election(): ?Election;

    abstract public function user(): User;

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
     * Nama pemilih pada tab yang dipilih. Super Admin tanpa saringan RT: semua RT.
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
     * Pemilih berhak (daftar beku saat Mulai) dibagi dua: belum menyelesaikan semua surat suara berhaknya, dan sudah.
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
