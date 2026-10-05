<?php

namespace App\Services\Voting;

use App\Enums\ElectionStatus;
use App\Enums\VoteStatus;
use App\Enums\WaveKind;
use App\Models\Attendee;
use App\Models\AttendeeParticipation;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Vote;
use App\Models\Wave;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Verifikasi PIN dan penyimpanan suara Mode Dadakan.
 *
 * Jaminan anti pemilihan ganda berlapis:
 *  1. baris peserta dikunci (SELECT ... FOR UPDATE) selama transaksi;
 *  2. unique index participation_one_active (peserta, surat suara, putaran);
 *  3. catatan kehadiran dan suara ditulis dalam satu transaksi (keduanya atau tidak sama sekali).
 */
class BallotBox
{
    public function __construct(
        private PinService $pins,
        private VoteLinker $linker,
        private AuditLogger $audit,
        private WaveManager $waves,
    ) {}

    /**
     * Peserta yang cocok dengan pencarian, belum memilih semua surat suara di putaran berjalan.
     *
     * @return Collection<int, Attendee>
     */
    public function search(Election $election, string $query): Collection
    {
        $normalized = Attendee::normalizeForSearch($query);
        $minChars = (int) $election->setting('search_min_chars');

        if (mb_strlen(str_replace(' ', '', $normalized)) < $minChars) {
            return collect();
        }

        $wave = $this->currentWaveOrFail($election);
        $round = $wave->round;
        $ballotCount = $election->ballots()->count();

        return Attendee::query()
            ->where('election_id', $election->id)
            ->where('name_search', 'like', '%'.addcslashes($normalized, '%_\\').'%')
            ->whereRaw(
                '(select count(*) from attendee_participations p where p.attendee_id = attendees.id and p.round_id = ? and p.active_key = 1) < ?',
                [$round->id, $ballotCount]
            )
            ->with('unit')
            ->orderBy('name')
            ->limit((int) $election->setting('search_max_results'))
            ->get();
    }

    /**
     * Mengembalikan wave id tempat PIN diverifikasi (disimpan di sesi pemilih).
     */
    public function verifyPin(Election $election, Attendee $attendee, #[SensitiveParameter] string $pin): Wave
    {
        $wave = $this->currentWaveOrFail($election);
        $maxAttempts = (int) $election->setting('pin_max_attempts');

        $outcome = DB::transaction(function () use ($attendee, $pin, $maxAttempts, $wave): array {
            $locked = Attendee::query()->whereKey($attendee->id)->lockForUpdate()->firstOrFail();

            if ($locked->isPinLocked()) {
                return ['ok' => false, 'locked' => true, 'left' => 0, 'just_locked' => false];
            }

            if ($this->hasVotedAllBallots($locked, $wave)) {
                return ['ok' => false, 'voted' => true];
            }

            if ($this->pins->matches($locked, $pin)) {
                if ($locked->pin_failed_attempts > 0) {
                    $locked->forceFill(['pin_failed_attempts' => 0])->save();
                }

                return ['ok' => true];
            }

            $attempts = $locked->pin_failed_attempts + 1;
            $justLocked = $attempts >= $maxAttempts;

            $locked->forceFill([
                'pin_failed_attempts' => $attempts,
                'pin_locked_at' => $justLocked ? Carbon::now() : null,
            ])->save();

            return ['ok' => false, 'locked' => $justLocked, 'left' => max(0, $maxAttempts - $attempts), 'just_locked' => $justLocked];
        });

        if ($outcome['ok']) {
            return $wave;
        }

        if ($outcome['voted'] ?? false) {
            throw VotingException::alreadyVoted();
        }

        if ($outcome['just_locked']) {
            $this->audit->log('attendee.pin_locked', $attendee, $election, actorType: 'voter');
        } elseif (! $outcome['locked']) {
            $this->audit->log('attendee.pin_failed', $attendee, $election, actorType: 'voter');
        }

        throw $outcome['locked'] ? VotingException::pinLocked() : VotingException::pinWrong($outcome['left']);
    }

    /**
     * Surat suara yang belum dipilih peserta di putaran gelombang tersebut.
     *
     * @return Collection<int, Ballot>
     */
    public function pendingBallots(Election $election, Attendee $attendee, Wave $wave): Collection
    {
        $done = AttendeeParticipation::query()
            ->where('attendee_id', $attendee->id)
            ->where('round_id', $wave->round_id)
            ->where('active_key', 1)
            ->pluck('ballot_id');

        return $election->ballots()->whereNotIn('id', $done)->get();
    }

    /**
     * Menyimpan satu suara. Panggilan ulang untuk surat suara yang sama melempar ALREADY_VOTED
     * (respons idempoten: layar menampilkan "Anda sudah memilih").
     */
    public function cast(Election $election, Attendee $attendee, Wave $verifiedWave, Ballot $ballot, Candidate $candidate): void
    {
        $grace = (int) $election->setting('late_grace_seconds');

        DB::transaction(function () use ($election, $attendee, $verifiedWave, $ballot, $candidate, $grace): void {
            $locked = Attendee::query()->whereKey($attendee->id)->lockForUpdate()->firstOrFail();
            $currentElection = Election::query()->whereKey($election->id)->firstOrFail();
            $wave = Wave::query()->whereKey($verifiedWave->id)->firstOrFail();

            if ($currentElection->status === ElectionStatus::Paused) {
                throw VotingException::paused();
            }

            if ($currentElection->status !== ElectionStatus::Berlangsung) {
                throw VotingException::timeUp();
            }

            if (! $wave->acceptsSubmissionAt($grace)) {
                throw $wave->status->value === 'DIJEDA' ? VotingException::paused() : VotingException::timeUp();
            }

            if ($locked->election_id !== $election->id || $ballot->election_id !== $election->id || $candidate->ballot_id !== $ballot->id) {
                throw VotingException::invalidChoice();
            }

            if (AttendeeParticipation::query()
                ->where('attendee_id', $locked->id)
                ->where('ballot_id', $ballot->id)
                ->where('round_id', $wave->round_id)
                ->where('active_key', 1)
                ->exists()) {
                throw VotingException::alreadyVoted();
            }

            try {
                $participation = new AttendeeParticipation;
                $participation->forceFill([
                    'attendee_id' => $locked->id,
                    'ballot_id' => $ballot->id,
                    'round_id' => $wave->round_id,
                    'wave_id' => $wave->id,
                    'is_assisted' => $wave->kind === WaveKind::Bantuan,
                    'active_key' => 1,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw VotingException::alreadyVoted();
            }

            $vote = new Vote;
            $vote->forceFill([
                'election_id' => $election->id,
                'ballot_id' => $ballot->id,
                'round_id' => $wave->round_id,
                'wave_id' => $wave->id,
                'candidate_id' => $candidate->id,
                'status' => VoteStatus::Sah,
                'voter_link' => $this->linker->link($locked, $ballot->id, $wave->round_id),
            ])->save();
        });
    }

    public function currentWaveOrFail(Election $election): Wave
    {
        $election->refresh();

        if ($election->status === ElectionStatus::Paused) {
            throw VotingException::paused();
        }

        if ($election->status !== ElectionStatus::Berlangsung) {
            throw VotingException::notOpen();
        }

        $this->waves->finalizeExpired($election);
        $wave = $election->openWave();

        if ($wave === null) {
            throw VotingException::notOpen();
        }

        if ($wave->status->value === 'DIJEDA') {
            throw VotingException::paused();
        }

        return $wave;
    }

    private function hasVotedAllBallots(Attendee $attendee, Wave $wave): bool
    {
        $voted = AttendeeParticipation::query()
            ->where('attendee_id', $attendee->id)
            ->where('round_id', $wave->round_id)
            ->where('active_key', 1)
            ->count();

        return $voted >= Ballot::query()->where('election_id', $attendee->election_id)->count();
    }
}
