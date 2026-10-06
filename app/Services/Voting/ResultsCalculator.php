<?php

namespace App\Services\Voting;

use App\Enums\VoteStatus;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Round;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Rekap hasil per surat suara per putaran. Hanya dipanggil setelah pemilihan ditutup,
 * kecuali partisipasi (tanpa angka per kandidat) yang boleh dilihat saat berlangsung.
 */
class ResultsCalculator
{
    /**
     * @return array{
     *     valid: int,
     *     cancelled: int,
     *     candidates: array<int, array{candidate: Candidate, votes: int, percent: float, rank: int}>,
     *     tie_at_top: bool
     * }
     */
    public function tally(Ballot $ballot, Round $round, ?Unit $unit = null): array
    {
        $candidates = $ballot->ballotCandidates()
            ->when($unit !== null, fn ($query) => $query->where('unit_id', $unit->id))
            ->get();

        $counts = DB::table('votes')
            ->where('ballot_id', $ballot->id)
            ->where('round_id', $round->id)
            ->where('status', VoteStatus::Sah->value)
            ->when($unit !== null, fn ($query) => $query->whereIn('candidate_id', $candidates->modelKeys()))
            ->groupBy('candidate_id')
            ->pluck(DB::raw('count(*)'), 'candidate_id');

        $valid = (int) $counts->sum();

        $cancelled = DB::table('votes')
            ->where('ballot_id', $ballot->id)
            ->where('round_id', $round->id)
            ->where('status', VoteStatus::Dibatalkan->value)
            ->when($unit !== null, fn ($query) => $query->whereIn('candidate_id', $candidates->modelKeys()))
            ->count();

        $rows = $candidates
            ->map(fn (Candidate $candidate): array => [
                'candidate' => $candidate,
                'votes' => (int) ($counts[$candidate->id] ?? 0),
                'percent' => $valid === 0 ? 0.0 : round(((int) ($counts[$candidate->id] ?? 0)) * 100 / $valid, 1),
            ])
            ->sortBy([['votes', 'desc'], [fn (array $row): int => $row['candidate']->number, 'asc']])
            ->values();

        $rank = 0;
        $previousVotes = null;
        $ranked = $rows->map(function (array $row, int $index) use (&$rank, &$previousVotes): array {
            if ($row['votes'] !== $previousVotes) {
                $rank = $index + 1;
                $previousVotes = $row['votes'];
            }

            return $row + ['rank' => $rank];
        })->all();

        $topVotes = $ranked[0]['votes'] ?? 0;
        $tieAtTop = $topVotes > 0 && collect($ranked)->where('votes', $topVotes)->count() > 1;

        return [
            'valid' => $valid,
            'cancelled' => $cancelled,
            'candidates' => $ranked,
            'tie_at_top' => $tieAtTop,
        ];
    }

    /**
     * Partisipasi (boleh dilihat saat berlangsung, tanpa angka per kandidat).
     *
     * @return array{attendees: int, voted: int, not_voted: int, percent: float, locked: int, assisted: int, late: int}
     */
    public function participation(Election $election, ?Round $round): array
    {
        $attendees = $election->attendees()->count();
        $ballotCount = max(1, $round === null ? $election->ballots()->count() : app(RoundResolver::class)->ballotIds($election, $round)->count());

        $voted = $round === null ? 0 : (int) DB::table('attendee_participations')
            ->where('round_id', $round->id)
            ->where('active_key', 1)
            ->select('attendee_id')
            ->groupBy('attendee_id')
            ->havingRaw('count(*) >= ?', [$ballotCount])
            ->get()
            ->count();

        $assisted = $round === null ? 0 : DB::table('attendee_participations')
            ->where('round_id', $round->id)
            ->where('active_key', 1)
            ->where('is_assisted', true)
            ->distinct()
            ->count('attendee_id');

        return [
            'attendees' => $attendees,
            'voted' => $voted,
            'not_voted' => max(0, $attendees - $voted),
            'percent' => $attendees === 0 ? 0.0 : round($voted * 100 / $attendees, 1),
            'locked' => $election->attendees()->whereNotNull('pin_locked_at')->count(),
            'assisted' => $assisted,
            'late' => $election->attendees()->where('is_late', true)->count(),
        ];
    }

    /**
     * Partisipasi Mode Resmi untuk satu surat suara (opsional satu RT). Penyebut = pemilih berhak
     * di snapshot (termasuk tambahan darurat), bukan seluruh pemilih (bagian 5A).
     *
     * @return array{eligible: int, voted: int, not_voted: int, percent: float, added_during_live: int}
     */
    public function ballotParticipation(Ballot $ballot, ?Round $round, ?int $unitId = null): array
    {
        $entries = DB::table('ballot_voters')
            ->where('ballot_id', $ballot->id)
            ->whereNull('revoked_at')
            ->when($unitId !== null, fn ($query) => $query->where('unit_id', $unitId));

        $eligible = (clone $entries)->count();
        $addedDuringLive = (clone $entries)->where('added_during_live', true)->count();

        // Setelah keterkaitan suara dihapus (K26), status "sudah memilih" diambil dari snapshot.
        $linkageDestroyed = $ballot->election()->value('vote_links_destroyed_at') !== null;

        $voted = match (true) {
            $round === null => 0,
            $linkageDestroyed => (clone $entries)->whereNotNull('voted_at')->count(),
            default => DB::table('votes')
                ->where('ballot_id', $ballot->id)
                ->where('round_id', $round->id)
                ->where('status', VoteStatus::Sah->value)
                ->whereIn('voter_id', (clone $entries)->select('voter_id'))
                ->count(),
        };

        return [
            'eligible' => $eligible,
            'voted' => $voted,
            'not_voted' => max(0, $eligible - $voted),
            'percent' => $eligible === 0 ? 0.0 : round($voted * 100 / $eligible, 1),
            'added_during_live' => $addedDuringLive,
        ];
    }

    /**
     * Rekonsiliasi Mode Resmi (bagian 10): setiap suara harus berasal dari izin yang sah,
     * dan jumlah suara per bilik dicatat untuk berita acara.
     *
     * @return array{votes_without_permit: int, per_booth: array<string, int>, permits_finished: int, permits_incomplete: int, permits_expired: int, permits_cancelled: int}
     */
    public function reconciliation(Election $election, ?int $unitId = null): array
    {
        $votes = DB::table('votes')
            ->where('votes.election_id', $election->id)
            ->when($unitId !== null, fn ($query) => $query->whereIn('votes.voter_id', DB::table('voters')->where('unit_id', $unitId)->select('id')));

        $perBooth = (clone $votes)
            ->join('devices', 'devices.id', '=', 'votes.device_id')
            ->join('units', 'units.id', '=', 'devices.unit_id')
            ->selectRaw("concat('RT', units.code, '-', lpad(devices.number, 2, '0')) as booth, count(*) as total")
            ->groupBy('units.code', 'devices.number')
            ->pluck('total', 'booth')
            ->map(fn ($total): int => (int) $total)
            ->all();

        $permits = DB::table('permits')
            ->where('election_id', $election->id)
            ->when($unitId !== null, fn ($query) => $query->where('unit_id', $unitId))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'votes_without_permit' => (clone $votes)->whereNull('votes.permit_id')->count(),
            'per_booth' => $perBooth,
            'permits_finished' => (int) ($permits['SELESAI'] ?? 0),
            'permits_incomplete' => (int) (($permits['TIDAK_SELESAI'] ?? 0) + ($permits['TERHENTI'] ?? 0)),
            'permits_expired' => (int) ($permits['HANGUS'] ?? 0),
            'permits_cancelled' => (int) ($permits['DIBATALKAN'] ?? 0),
        ];
    }
}
