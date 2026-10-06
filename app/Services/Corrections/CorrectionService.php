<?php

namespace App\Services\Corrections;

use App\Enums\BallotScope;
use App\Enums\CorrectionReason;
use App\Enums\CorrectionStatus;
use App\Enums\ElectionStatus;
use App\Enums\VoteStatus;
use App\Models\Ballot;
use App\Models\Election;
use App\Models\User;
use App\Models\Vote;
use App\Models\VoteCancellation;
use App\Models\VoteCorrection;
use App\Models\Voter;
use App\Services\AuditLogger;
use App\Services\InternalNotifier;
use App\Services\Results\OfficialReportService;
use App\Services\Voting\VotingException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Koreksi suara Mode Resmi (bagian 8, K16, K17):
 * - diajukan Admin RT (RT sendiri) atau Super Admin, diputuskan Super Admin
 * - akun yang memberi izin untuk suara itu tidak boleh menjadi pengaju maupun penyetuju
 * - suara lama berstatus DIBATALKAN (tidak dihapus); pilihan tidak pernah ditampilkan
 * - selama berlangsung pemilih bisa diizinkan memilih ulang; setelah ditutup hanya pembatalan
 */
class CorrectionService
{
    /**
     * @var array<int, ElectionStatus>
     */
    private const ALLOWED_STATUSES = [ElectionStatus::Berlangsung, ElectionStatus::Paused, ElectionStatus::Ditutup, ElectionStatus::Verifikasi, ElectionStatus::Unpublished];

    public function __construct(
        private AuditLogger $audit,
        private InternalNotifier $notifier,
        private OfficialReportService $reports,
    ) {}

    public function request(Election $election, Voter $voter, Ballot $ballot, CorrectionReason $reason, ?string $note, User $actor): VoteCorrection
    {
        $this->assertStatus($election);

        $mayRequest = $actor->isSuperAdmin() || ($actor->isAdminRt() && $actor->unit_id === $voter->unit_id);
        abort_unless($mayRequest && $ballot->election_id === $election->id, 403);

        if ($reason === CorrectionReason::Lainnya && blank($note)) {
            throw VotingException::invalidState('Alasan "Lainnya" wajib disertai catatan.');
        }

        $vote = $this->validVote($election, $voter, $ballot);

        if ($vote === null) {
            throw VotingException::invalidState('Pemilih ini tidak punya suara sah pada surat suara tersebut.');
        }

        if ($this->granterOf($vote) === $actor->id) {
            throw VotingException::invalidState('Petugas yang memberi izin untuk suara ini tidak boleh mengajukan pembatalannya (K17). Minta Admin RT lain atau Super Admin.');
        }

        try {
            $correction = new VoteCorrection;
            $correction->forceFill([
                'public_id' => (string) Str::ulid(),
                'election_id' => $election->id,
                'vote_id' => $vote->id,
                'voter_id' => $voter->id,
                'ballot_id' => $ballot->id,
                'unit_id' => $voter->unit_id,
                'reason_code' => $reason,
                'note' => $note,
                'status' => CorrectionStatus::Diajukan,
                'requested_by' => $actor->id,
                'pending_key' => 1,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw VotingException::invalidState('Sudah ada pengajuan koreksi yang menunggu untuk suara ini.');
        }

        $this->audit->log('correction.requested', $correction, $election, reasonCode: $reason->value, note: $note, meta: [
            'voter' => $voter->voter_number,
            'ballot' => $ballot->title,
        ], actor: $actor);

        $this->notifier->notifySuperAdmins(
            'Pengajuan koreksi suara',
            "{$voter->unit->name}: pengajuan pembatalan suara {$ballot->title} untuk {$voter->voter_number} ({$reason->getLabel()}).",
            $actor,
        );

        return $correction;
    }

    public function approve(VoteCorrection $correction, User $actor, ?string $decisionNote = null): void
    {
        abort_unless($actor->isSuperAdmin(), 403);

        $election = $correction->election;
        $this->assertStatus($election);

        DB::transaction(function () use ($correction, $actor, $decisionNote): void {
            $locked = VoteCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CorrectionStatus::Diajukan) {
                throw VotingException::invalidState('Pengajuan ini sudah diputuskan.');
            }

            $vote = Vote::query()->whereKey($locked->vote_id)->lockForUpdate()->firstOrFail();

            if ($this->granterOf($vote) === $actor->id) {
                throw VotingException::invalidState('Penyetuju tidak boleh akun yang memberi izin untuk suara ini (K17).');
            }

            if ($vote->status === VoteStatus::Sah) {
                $vote->forceFill(['status' => VoteStatus::Dibatalkan, 'active_key' => null])->save();

                $cancellation = new VoteCancellation;
                $cancellation->forceFill([
                    'vote_id' => $vote->id,
                    'election_id' => $locked->election_id,
                    'reason_code' => $locked->reason_code->value,
                    'note' => $locked->note,
                    'actor_id' => $actor->id,
                ])->save();
            }

            $locked->forceFill([
                'status' => CorrectionStatus::Disetujui,
                'decided_by' => $actor->id,
                'decided_at' => Carbon::now(),
                'decision_note' => $decisionNote,
                'pending_key' => null,
            ])->save();

            $correction->setRawAttributes($locked->getAttributes(), true);
        });

        if (in_array($election->fresh()->status, [ElectionStatus::Verifikasi, ElectionStatus::Unpublished], true)) {
            $ballot = $correction->ballot;
            $this->reports->invalidate($election, $ballot->scope === BallotScope::PerRt ? $correction->unit : null, $actor);
        }

        $this->audit->log('correction.approved', $correction, $election, reasonCode: $correction->reason_code->value, note: $decisionNote, meta: [
            'voter' => $correction->voter->voter_number,
            'ballot' => $correction->ballot->title,
            'can_revote' => $election->fresh()->status->isLive(),
        ], actor: $actor);
    }

    public function reject(VoteCorrection $correction, User $actor, string $decisionNote): void
    {
        abort_unless($actor->isSuperAdmin(), 403);

        $this->decide($correction, CorrectionStatus::Ditolak, $actor, $decisionNote);
        $this->audit->log('correction.rejected', $correction, $correction->election, note: $decisionNote, actor: $actor);
    }

    public function withdraw(VoteCorrection $correction, User $actor): void
    {
        abort_unless($correction->requested_by === $actor->id, 403);

        $this->decide($correction, CorrectionStatus::Ditarik, $actor, null);
        $this->audit->log('correction.withdrawn', $correction, $correction->election, actor: $actor);
    }

    public function validVote(Election $election, Voter $voter, Ballot $ballot): ?Vote
    {
        return Vote::query()
            ->where('election_id', $election->id)
            ->where('ballot_id', $ballot->id)
            ->where('voter_id', $voter->id)
            ->where('status', VoteStatus::Sah)
            ->latest('round_id')
            ->first();
    }

    private function decide(VoteCorrection $correction, CorrectionStatus $status, User $actor, ?string $note): void
    {
        DB::transaction(function () use ($correction, $status, $actor, $note): void {
            $locked = VoteCorrection::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CorrectionStatus::Diajukan) {
                throw VotingException::invalidState('Pengajuan ini sudah diputuskan.');
            }

            $locked->forceFill([
                'status' => $status,
                'decided_by' => $actor->id,
                'decided_at' => Carbon::now(),
                'decision_note' => $note,
                'pending_key' => null,
            ])->save();

            $correction->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function granterOf(Vote $vote): ?int
    {
        return $vote->permit_id === null ? null : (int) DB::table('permits')->where('id', $vote->permit_id)->value('granted_by');
    }

    private function assertStatus(Election $election): void
    {
        if (! in_array($election->status, self::ALLOWED_STATUSES, true)) {
            throw VotingException::invalidState('Koreksi suara hanya bisa saat pemilihan berlangsung atau sebelum hasil dipublikasikan.');
        }
    }
}
