<?php

namespace App\Services\Voting;

use App\Enums\ElectionStatus;
use App\Enums\RestoreReason;
use App\Enums\VoteStatus;
use App\Models\Attendee;
use App\Models\AttendeeParticipation;
use App\Models\Election;
use App\Models\User;
use App\Models\Vote;
use App\Models\VoteCancellation;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Pulihkan Hak Pilih" Mode Dadakan (bagian 6.7 kebutuhan, 8.7 Tahap 1).
 *
 * Jika peserta sudah memilih di putaran berjalan, suaranya ditemukan lewat tautan sementara
 * lalu berstatus DIBATALKAN (tidak dihapus). PIN selalu dibuat baru; PIN lama hangus.
 * Pilihan lama tidak pernah dikembalikan ke pemanggil.
 */
class VoterRightRestorer
{
    public function __construct(
        private AttendeeRegistrar $registrar,
        private VoteLinker $linker,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  bool  $onlyIfNotVoted  Tombol "PIN baru": tolak bila peserta ternyata sudah memilih, agar tidak ada suara yang ikut dibatalkan.
     * @return array{pin: string, cancelled_votes: int}
     */
    public function restore(Election $election, Attendee $attendee, RestoreReason $reason, ?string $note, User $actor, bool $onlyIfNotVoted = false): array
    {
        if (! $election->status->isLive()) {
            throw VotingException::invalidState('Pulihkan Hak Pilih hanya bisa saat pemilihan berlangsung.');
        }

        if ($attendee->election_id !== $election->id) {
            throw VotingException::invalidChoice();
        }

        if ($reason === RestoreReason::Lainnya && blank($note)) {
            throw VotingException::invalidState('Alasan "Lainnya" wajib disertai catatan.');
        }

        $round = $election->currentRound();

        $result = DB::transaction(function () use ($election, $attendee, $reason, $note, $actor, $round, $onlyIfNotVoted): array {
            $locked = Attendee::query()->whereKey($attendee->id)->lockForUpdate()->firstOrFail();
            $cancelled = 0;

            $participations = AttendeeParticipation::query()
                ->where('attendee_id', $locked->id)
                ->where('round_id', $round->id)
                ->where('active_key', 1)
                ->lockForUpdate()
                ->get();

            if ($onlyIfNotVoted && $participations->isNotEmpty()) {
                throw VotingException::invalidState("{$locked->name} sudah tercatat memilih. Bila memang perlu, pakai tombol Pulihkan.");
            }

            foreach ($participations as $participation) {
                $link = $this->linker->link($locked, $participation->ballot_id, $participation->round_id);

                $vote = Vote::query()
                    ->where('round_id', $participation->round_id)
                    ->where('ballot_id', $participation->ballot_id)
                    ->where('voter_link', $link)
                    ->where('status', VoteStatus::Sah)
                    ->lockForUpdate()
                    ->first();

                if ($vote !== null) {
                    $vote->status = VoteStatus::Dibatalkan;
                    $vote->voter_link = null;
                    $vote->save();

                    $cancellation = new VoteCancellation;
                    $cancellation->forceFill([
                        'vote_id' => $vote->id,
                        'election_id' => $election->id,
                        'reason_code' => $reason->value,
                        'note' => $note,
                        'actor_id' => $actor->id,
                    ])->save();

                    $cancelled++;
                }

                $participation->forceFill(['active_key' => null, 'cancelled_at' => Carbon::now()])->save();
            }

            $pin = $this->registrar->reissuePin($locked);

            return ['pin' => $pin, 'cancelled_votes' => $cancelled];
        });

        $this->audit->log('attendee.voting_right_restored', $attendee, $election, reasonCode: $reason->value, note: $note, meta: [
            'round' => $round->number,
            'had_voted' => $result['cancelled_votes'] > 0,
            'cancelled_votes' => $result['cancelled_votes'],
            'pin_reissued' => true,
        ], actor: $actor);

        return $result;
    }

    public function statusAllowsRestore(Election $election): bool
    {
        return in_array($election->status, [ElectionStatus::Berlangsung, ElectionStatus::Paused], true);
    }
}
