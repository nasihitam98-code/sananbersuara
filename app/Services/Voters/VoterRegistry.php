<?php

namespace App\Services\Voters;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Models\Attendee;
use App\Models\BallotVoter;
use App\Models\Election;
use App\Models\Permit;
use App\Models\User;
use App\Models\VoteCorrection;
use App\Models\Voter;
use App\Services\AuditLogger;
use App\Services\InternalNotifier;
use App\Services\Voting\VotingException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Tambah, ubah, nonaktifkan, dan hapus pemilih (bagian 5.8 dan 5A), termasuk aturan duplikat K12:
 * - NIK sama di mana pun                  -> ditolak
 * - nama + tanggal lahir sama di RT lain   -> ditolak ("terdaftar di RT lain")
 * - nama sama saja                         -> peringatan; harus dikonfirmasi "orang berbeda"
 */
class VoterRegistry
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{name: string, address?: ?string, gender?: ?string, birth_date?: ?string, phone?: ?string}  $data
     * @return array{blocked: ?string, similar: array<int, string>}
     */
    public function checkDuplicates(array $data, int $unitId, #[SensitiveParameter] ?string $nik = null, ?Voter $ignore = null): array
    {
        $nik = Voter::normalizeNik($nik);
        $nameSearch = Attendee::normalizeForSearch($data['name']);
        $query = fn () => Voter::query()->with('unit')->when($ignore !== null, fn ($builder) => $builder->whereKeyNot($ignore->id));

        if ($nik !== null) {
            $sameNik = $query()->where('nik_hash', Voter::hashNik($nik))->first();

            if ($sameNik !== null) {
                return ['blocked' => $sameNik->unit_id === $unitId
                    ? "NIK ini sudah terdaftar di {$sameNik->unit->name} ({$sameNik->voter_number})."
                    : 'Warga ini terdaftar di RT lain, memilih di RT asalnya.', 'similar' => []];
            }
        }

        if (filled($data['birth_date'] ?? null)) {
            $sameIdentity = $query()
                ->where('name_search', $nameSearch)
                ->whereDate('birth_date', Carbon::parse($data['birth_date']))
                ->first();

            if ($sameIdentity !== null) {
                return ['blocked' => $sameIdentity->unit_id === $unitId
                    ? "Nama dan tanggal lahir sama sudah terdaftar di {$sameIdentity->unit->name} ({$sameIdentity->voter_number})."
                    : 'Warga ini terdaftar di RT lain, memilih di RT asalnya.', 'similar' => []];
            }
        }

        $similar = $query()
            ->where('name_search', $nameSearch)
            ->get()
            ->map(fn (Voter $voter): string => collect([$voter->name, $voter->unit->name, $voter->address])->filter()->implode(' · '))
            ->all();

        return ['blocked' => null, 'similar' => $similar];
    }

    /**
     * @param  array{name: string, address?: ?string, gender?: ?string, birth_date?: ?string, phone?: ?string}  $data
     */
    public function create(array $data, int $unitId, User $actor, #[SensitiveParameter] ?string $nik = null, bool $confirmedDifferentPerson = false, ?string $emergencyReason = null): Voter
    {
        abort_unless($actor->canManageVotersOf($unitId), 403);

        $live = $this->liveResmiElection();

        if ($live !== null && blank($emergencyReason)) {
            throw VotingException::invalidState('Pemilihan sedang berlangsung: daftar pemilih dibekukan. Gunakan "Tambah Pemilih Darurat" dengan alasan.');
        }

        $check = $this->checkDuplicates($data, $unitId, $nik);

        if ($check['blocked'] !== null) {
            $this->audit->log('voter.duplicate_rejected', meta: ['unit_id' => $unitId, 'reason' => $check['blocked']], actor: $actor);

            throw VotingException::invalidState($check['blocked']);
        }

        if ($check['similar'] !== [] && ! $confirmedDifferentPerson) {
            throw new DuplicateNameWarning($check['similar']);
        }

        $voter = DB::transaction(function () use ($data, $unitId, $actor, $nik, $live, $emergencyReason): Voter {
            $voter = new Voter([...$data, 'address' => trim((string) ($data['address'] ?? ''))]);
            $voter->unit_id = $unitId;
            $voter->setNik($nik);
            $voter->created_by = $actor->id;
            $voter->added_during_live = $live !== null;
            $voter->save();

            if ($live !== null) {
                app(EligibilitySnapshot::class)->addDuringLive($live, $voter, (string) $emergencyReason);
            }

            return $voter;
        });

        $this->audit->log($live !== null ? 'voter.emergency_added' : 'voter.created', $voter, $live, reasonCode: $emergencyReason, meta: [
            'unit_id' => $unitId,
            'confirmed_different_person' => $confirmedDifferentPerson && $check['similar'] !== [],
        ], actor: $actor);

        if ($live !== null) {
            app(InternalNotifier::class)->notifySuperAdmins(
                'Pemilih ditambah saat berlangsung',
                "{$actor->name} menambah {$voter->voter_number} di {$voter->unit->name} (alasan: {$emergencyReason}).",
                $actor,
            );
        }

        return $voter;
    }

    /**
     * @param  array{name: string, address?: ?string, gender?: ?string, birth_date?: ?string, phone?: ?string}  $data
     */
    public function update(Voter $voter, array $data, User $actor, #[SensitiveParameter] ?string $nik = null, bool $changeNik = false): Voter
    {
        abort_unless($actor->canManageVotersOf($voter->unit_id), 403);

        $check = $this->checkDuplicates($data, $voter->unit_id, $changeNik ? $nik : null, $voter);

        if ($check['blocked'] !== null) {
            throw VotingException::invalidState($check['blocked']);
        }

        $before = $voter->only(['name', 'address', 'gender', 'birth_date']);
        $voter->fill([...$data, 'address' => trim((string) ($data['address'] ?? ''))]);

        if ($changeNik) {
            $voter->setNik($nik);
        }

        $voter->save();

        $this->audit->log('voter.updated', $voter, meta: [
            'before' => $before,
            'after' => $voter->only(['name', 'address', 'gender', 'birth_date']),
            'nik_changed' => $changeNik,
        ], actor: $actor);

        return $voter;
    }

    /**
     * Koreksi RT hanya oleh Super Admin dengan alasan. Snapshot pemilihan yang sudah berjalan tidak ikut berubah (K09).
     */
    public function moveUnit(Voter $voter, int $unitId, string $reason, User $actor): void
    {
        abort_unless($actor->isSuperAdmin(), 403);

        $from = $voter->unit_id;
        $voter->unit_id = $unitId;
        $voter->save();

        $this->audit->log('voter.unit_corrected', $voter, reasonCode: 'KOREKSI_RT', note: $reason, meta: ['from_unit' => $from, 'to_unit' => $unitId], actor: $actor);
    }

    public function deactivate(Voter $voter, string $reason, User $actor): void
    {
        abort_unless($actor->canManageVotersOf($voter->unit_id), 403);

        $voter->forceFill(['is_active' => false, 'inactive_reason' => $reason])->save();

        $this->audit->log('voter.deactivated', $voter, note: $reason, actor: $actor);
    }

    public function reactivate(Voter $voter, User $actor): void
    {
        abort_unless($actor->canManageVotersOf($voter->unit_id), 403);

        $voter->forceFill(['is_active' => true, 'inactive_reason' => null])->save();

        $this->audit->log('voter.reactivated', $voter, actor: $actor);
    }

    /**
     * Hard delete hanya jika belum pernah punya riwayat suara/hak pilih; selain itu harus nonaktif.
     */
    public function delete(Voter $voter, User $actor): void
    {
        abort_unless($actor->canManageVotersOf($voter->unit_id), 403);

        if ($voter->hasVotingHistory() || $voter->ballotEntries()->exists()) {
            throw VotingException::invalidState('Pemilih ini punya riwayat pemilihan dan tidak bisa dihapus. Nonaktifkan saja.');
        }

        $this->audit->log('voter.deleted', $voter, meta: ['voter_number' => $voter->voter_number, 'unit_id' => $voter->unit_id], actor: $actor);
        $voter->delete();
    }

    /**
     * Pemilih darurat yang ternyata salah tulis/tidak jadi boleh dihapus dari Meja Izin selama voting, asalkan belum
     * pernah diberi izin (keputusan pemilik 2026-10-29). Yang sudah diberi izin atau mencoblos tetap tercatat.
     */
    public function canDeleteEmergency(Voter $voter): bool
    {
        return $voter->added_during_live
            && $this->liveResmiElection() !== null
            && ! $voter->votes()->exists()
            && ! Permit::query()->where('voter_id', $voter->id)->exists()
            && ! VoteCorrection::query()->where('voter_id', $voter->id)->exists();
    }

    public function deleteEmergency(Voter $voter, User $actor): void
    {
        abort_unless($actor->canManageVotersOf($voter->unit_id), 403);

        if (! $this->canDeleteEmergency($voter)) {
            throw VotingException::invalidState('Hanya pemilih darurat yang belum pernah diberi izin memilih yang bisa dihapus.');
        }

        $live = $this->liveResmiElection();
        $snapshot = ['name' => $voter->name, 'voter_number' => $voter->voter_number, 'unit_id' => $voter->unit_id];

        DB::transaction(function () use ($voter): void {
            $voter->ballotEntries()->delete();
            $voter->delete();
        });

        $this->audit->log('voter.emergency_deleted', null, $live, meta: $snapshot, actor: $actor);

        app(InternalNotifier::class)->notifySuperAdmins(
            'Pemilih darurat dihapus',
            "{$actor->name} menghapus pemilih darurat {$snapshot['name']} ({$snapshot['voter_number']}).",
            $actor,
        );
    }

    /**
     * Nama dari data utama yang ternyata tidak berhak (dobel, bukan warga RT ini, pindah, meninggal) dicoret dari daftar
     * pemilihan yang sedang berjalan, tanpa menghapus datanya: hak pilihnya dicabut (revoked) dan pemilih dinonaktifkan.
     * Hanya selama belum pernah diberi izin memilih (keputusan pemilik 2026-10-29). Tercatat di berita acara.
     */
    public function canStrikeFromLiveList(Voter $voter): bool
    {
        $live = $this->liveResmiElection();

        return $live !== null
            && ! $voter->added_during_live
            && $this->liveEntries($voter, $live)->whereNull('revoked_at')->exists()
            && ! $voter->votes()->where('election_id', $live->id)->exists()
            && ! Permit::query()->where('election_id', $live->id)->where('voter_id', $voter->id)->exists();
    }

    public function strikeFromLiveList(Voter $voter, string $reason, User $actor): void
    {
        abort_unless($actor->canManageVotersOf($voter->unit_id), 403);

        if (! $this->canStrikeFromLiveList($voter)) {
            throw VotingException::invalidState('Hanya pemilih yang belum pernah diberi izin memilih yang bisa dihapus dari daftar.');
        }

        $live = $this->liveResmiElection();

        DB::transaction(function () use ($voter, $reason, $live): void {
            $this->liveEntries($voter, $live)->whereNull('revoked_at')->update(['revoked_at' => Carbon::now(), 'revoked_reason' => $reason]);
            $voter->forceFill(['is_active' => false, 'inactive_reason' => $reason])->save();
        });

        $this->audit->log('voter.struck_during_live', $voter, $live, note: $reason, meta: ['voter_number' => $voter->voter_number, 'unit_id' => $voter->unit_id], actor: $actor);

        app(InternalNotifier::class)->notifySuperAdmins(
            'Pemilih dihapus dari daftar',
            "{$actor->name} menghapus {$voter->name} ({$voter->voter_number}) dari daftar pemilihan. Alasan: {$reason}.",
            $actor,
        );
    }

    public function canRestoreToLiveList(Voter $voter): bool
    {
        $live = $this->liveResmiElection();

        return $live !== null && $this->liveEntries($voter, $live)->whereNotNull('revoked_at')->exists();
    }

    /**
     * Salah coret: kembalikan hak pilih di pemilihan yang sedang berjalan dan aktifkan lagi pemilihnya.
     */
    public function restoreToLiveList(Voter $voter, User $actor): void
    {
        abort_unless($actor->canManageVotersOf($voter->unit_id), 403);

        if (! $this->canRestoreToLiveList($voter)) {
            throw VotingException::invalidState('Pemilih ini tidak sedang dihapus dari daftar.');
        }

        $live = $this->liveResmiElection();

        DB::transaction(function () use ($voter, $live): void {
            $this->liveEntries($voter, $live)->whereNotNull('revoked_at')->update(['revoked_at' => null, 'revoked_reason' => null]);
            $voter->forceFill(['is_active' => true, 'inactive_reason' => null])->save();
        });

        $this->audit->log('voter.restored_during_live', $voter, $live, meta: ['voter_number' => $voter->voter_number, 'unit_id' => $voter->unit_id], actor: $actor);
    }

    /**
     * @return HasMany<BallotVoter, Voter>
     */
    private function liveEntries(Voter $voter, Election $live): HasMany
    {
        return $voter->ballotEntries()->whereIn('ballot_id', $live->ballots()->select('id'));
    }

    public function liveResmiElection(): ?Election
    {
        return Election::query()
            ->where('mode', ElectionMode::Resmi)
            ->whereIn('status', [ElectionStatus::Berlangsung, ElectionStatus::Paused])
            ->first();
    }
}
