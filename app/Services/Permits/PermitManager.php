<?php

namespace App\Services\Permits;

use App\Enums\DeviceKind;
use App\Enums\ElectionStatus;
use App\Enums\PermitCancelReason;
use App\Enums\PermitStatus;
use App\Enums\VoteStatus;
use App\Models\Ballot;
use App\Models\BallotVoter;
use App\Models\Device;
use App\Models\Election;
use App\Models\Permit;
use App\Models\User;
use App\Models\Vote;
use App\Models\Voter;
use App\Services\AuditLogger;
use App\Services\Voting\VotingException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Izin memilih Mode Resmi (bagian 5.5, 7.5–7.7):
 * - bilik kosong dipilih server secara atomik (baris dikunci + unique index)
 * - satu pemilih satu izin aktif; dua petugas menekan bersamaan -> hanya satu berhasil
 * - izin hangus otomatis jika tidak disentuh; sesi berakhir jika pemilih diam terlalu lama
 */
class PermitManager
{
    public function __construct(private AuditLogger $audit) {}

    public function grant(Voter $voter, User $actor, Device $desk): Permit
    {
        $election = $desk->election->fresh();
        $this->assertDesk($desk, $actor, $voter->unit_id);

        if ($election->status === ElectionStatus::Paused) {
            throw VotingException::invalidState('Pemilihan sedang dijeda. Izin baru belum bisa diberikan.');
        }

        if ($election->status !== ElectionStatus::Berlangsung) {
            throw VotingException::invalidState('Pemilihan tidak sedang berlangsung.');
        }

        $this->expireStale($election);

        for ($attempt = 1; ; $attempt++) {
            try {
                $permit = $this->createPermit($voter, $actor, $desk, $election);

                break;
            } catch (UniqueConstraintViolationException $exception) {
                // Bilik yang sama baru saja dipakai izin lain: coba bilik kosong berikutnya.
                if ($attempt >= 3) {
                    throw VotingException::invalidState('Pemilih atau bilik baru saja diproses petugas lain. Coba lagi.');
                }
            }
        }

        $this->audit->log('permit.granted', $permit, $election, meta: [
            'voter' => $voter->voter_number,
            'booth' => $permit->device->code(),
            'desk' => $desk->code(),
        ], actor: $actor);

        return $permit;
    }

    private function createPermit(Voter $voter, User $actor, Device $desk, Election $election): Permit
    {
        return DB::transaction(function () use ($voter, $actor, $desk, $election): Permit {
            $voter = Voter::query()->whereKey($voter->id)->lockForUpdate()->firstOrFail();
            $round = $election->currentRound();

            if ($this->pendingBallots($voter, $election)->isEmpty()) {
                throw $this->eligibleBallotIds($voter, $election)->isEmpty()
                    ? VotingException::invalidState('Tidak berhak di pemilihan ini.')
                    : VotingException::alreadyVoted();
            }

            $active = Permit::query()->where('election_id', $election->id)->where('active_voter_id', $voter->id)->with('device')->first();

            if ($active !== null) {
                throw VotingException::invalidState('Pemilih ini sedang di '.$active->device->name().'.');
            }

            $booth = Device::query()
                ->where('election_id', $election->id)
                ->where('unit_id', $voter->unit_id)
                ->where('kind', DeviceKind::Bilik)
                ->whereNotNull('session_secret_hash')
                ->where('last_seen_at', '>', Carbon::now()->subSeconds(Device::DISCONNECT_AFTER_SECONDS))
                ->whereDoesntHave('activePermit')
                ->orderByRaw('last_permit_ended_at is not null, last_permit_ended_at asc')
                ->orderBy('number')
                ->lockForUpdate()
                ->first();

            if ($booth === null) {
                throw VotingException::invalidState('Semua bilik terpakai. Tunggu bilik kosong.');
            }

            $permit = new Permit;
            $permit->forceFill([
                'public_id' => (string) Str::ulid(),
                'election_id' => $election->id,
                'round_id' => $round->id,
                'voter_id' => $voter->id,
                'unit_id' => $voter->unit_id,
                'device_id' => $booth->id,
                'desk_device_id' => $desk->id,
                'granted_by' => $actor->id,
                'status' => PermitStatus::Aktif,
                'granted_at' => Carbon::now(),
                'active_voter_id' => $voter->id,
                'active_device_id' => $booth->id,
            ])->save();

            return $permit->setRelation('device', $booth);
        });
    }

    /**
     * Batalkan izin (salah klik nama, dll.) hanya selama belum ada suara yang dikonfirmasi lewat izin ini.
     */
    public function cancel(Permit $permit, PermitCancelReason $reason, User $actor, Device $desk): void
    {
        $this->assertDesk($desk, $actor, $permit->unit_id);

        DB::transaction(function () use ($permit, $actor): void {
            $locked = Permit::query()->whereKey($permit->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isActive()) {
                throw VotingException::invalidState('Izin ini sudah tidak aktif.');
            }

            if ($locked->votes()->exists()) {
                throw VotingException::invalidState('Pemilih sudah mengonfirmasi sebagian surat suara. Hentikan lewat "Lepas bilik" atau tunggu selesai; suara yang sudah masuk hanya bisa dibatalkan lewat koreksi.');
            }

            $this->end($locked, PermitStatus::Dibatalkan, null, $actor);
        });

        $this->audit->log('permit.cancelled', $permit, $permit->election, reasonCode: $reason->value, actor: $actor);
    }

    /**
     * Sentuhan pertama di bilik: izin menjadi DIPAKAI. Setiap aktivitas memperbarui waktu terakhir.
     */
    public function touch(Permit $permit): void
    {
        $permit->forceFill([
            'status' => PermitStatus::Dipakai,
            'started_at' => $permit->started_at ?? Carbon::now(),
            'last_activity_at' => Carbon::now(),
        ])->save();
    }

    /**
     * Izin AKTIF tak disentuh melewati batas -> HANGUS; DIPAKAI tapi diam -> TIDAK_SELESAI (K05).
     */
    public function expireStale(Election $election): int
    {
        $expiry = Carbon::now()->subMinutes((int) $election->setting('permit_expiry_minutes'));
        $idle = Carbon::now()->subMinutes((int) $election->setting('booth_idle_minutes'));
        $ended = 0;

        $stale = Permit::query()
            ->where('election_id', $election->id)
            ->where(fn ($query) => $query
                ->where(fn ($aktif) => $aktif->where('status', PermitStatus::Aktif)->where('granted_at', '<', $expiry))
                ->orWhere(fn ($dipakai) => $dipakai->where('status', PermitStatus::Dipakai)->where('last_activity_at', '<', $idle)))
            ->get();

        foreach ($stale as $permit) {
            DB::transaction(function () use ($permit, &$ended): void {
                $locked = Permit::query()->whereKey($permit->id)->lockForUpdate()->first();

                if ($locked === null || ! $locked->status->isActive()) {
                    return;
                }

                $status = $locked->status === PermitStatus::Aktif ? PermitStatus::Hangus : PermitStatus::TidakSelesai;
                $this->end($locked, $status, 'WAKTU_HABIS', null);
                $ended++;

                $this->audit->log($status === PermitStatus::Hangus ? 'permit.expired' : 'permit.incomplete', $locked, $locked->election, actorType: 'system');
            });
        }

        return $ended;
    }

    /**
     * Pemilihan dijeda/ditutup paksa: izin aktif dihentikan.
     */
    public function stopAll(Election $election, string $reason): void
    {
        Permit::query()
            ->where('election_id', $election->id)
            ->whereIn('status', [PermitStatus::Aktif, PermitStatus::Dipakai])
            ->get()
            ->each(fn (Permit $permit) => $this->end($permit, PermitStatus::Terhenti, $reason, null));
    }

    public function end(Permit $permit, PermitStatus $status, ?string $reason, ?User $actor): void
    {
        $permit->forceFill([
            'status' => $status,
            'ended_at' => Carbon::now(),
            'end_reason' => $reason,
            'ended_by' => $actor?->id,
            'active_voter_id' => null,
            'active_device_id' => null,
        ])->save();

        Device::query()->whereKey($permit->device_id)->update(['last_permit_ended_at' => Carbon::now()]);
    }

    /**
     * @return Collection<int, int>
     */
    public function eligibleBallotIds(Voter $voter, Election $election): Collection
    {
        return BallotVoter::query()
            ->where('voter_id', $voter->id)
            ->whereNull('revoked_at')
            ->whereIn('ballot_id', $election->ballots()->pluck('id'))
            ->pluck('ballot_id');
    }

    /**
     * Surat suara yang berhak dan belum dipilih di putaran berjalan, urut sesuai urutan surat suara.
     *
     * @return Collection<int, Ballot>
     */
    public function pendingBallots(Voter $voter, Election $election): Collection
    {
        $round = $election->currentRound();
        $voted = Vote::query()
            ->where('voter_id', $voter->id)
            ->where('round_id', $round?->id)
            ->where('status', VoteStatus::Sah)
            ->pluck('ballot_id');

        return $election->ballots()
            ->whereIn('id', $this->eligibleBallotIds($voter, $election))
            ->whereNotIn('id', $voted)
            ->get();
    }

    /**
     * Status per pemilih untuk Meja Izin dan dashboard: per surat suara TIDAK_BERHAK / BELUM / SUDAH,
     * plus bilik jika sedang memegang izin aktif. Tanpa pilihan kandidat.
     *
     * @param  Collection<int, Voter>  $voters
     * @return array<int, array{ballots: array<int, string>, permit: ?Permit}>
     */
    public function statuses(Collection $voters, Election $election): array
    {
        $round = $election->currentRound();
        $ids = $voters->modelKeys();
        $ballotIds = $election->ballots()->pluck('id');

        $eligible = BallotVoter::query()->whereIn('voter_id', $ids)->whereIn('ballot_id', $ballotIds)->whereNull('revoked_at')
            ->get(['voter_id', 'ballot_id'])->groupBy('voter_id');
        $voted = Vote::query()->whereIn('voter_id', $ids)->where('round_id', $round?->id)->where('status', VoteStatus::Sah)
            ->get(['voter_id', 'ballot_id'])->groupBy('voter_id');
        $permits = Permit::query()->where('election_id', $election->id)->whereIn('active_voter_id', $ids)->with('device.unit')->get()->keyBy('voter_id');

        $result = [];

        foreach ($voters as $voter) {
            $eligibleIds = $eligible->get($voter->id, collect())->pluck('ballot_id')->all();
            $votedIds = $voted->get($voter->id, collect())->pluck('ballot_id')->all();

            $result[$voter->id] = [
                'ballots' => $ballotIds->mapWithKeys(fn (int $ballotId): array => [$ballotId => match (true) {
                    ! in_array($ballotId, $eligibleIds, true) => 'TIDAK_BERHAK',
                    in_array($ballotId, $votedIds, true) => 'SUDAH',
                    default => 'BELUM',
                }])->all(),
                'permit' => $permits->get($voter->id),
            ];
        }

        return $result;
    }

    /**
     * Petugas meja harus: Admin RT dengan izin Petugas Meja, memakai laptop Meja terdaftar RT yang sama
     * dengan pemilih (bagian 3.2: siapa + dari mana + kapan).
     */
    public function assertDesk(Device $desk, User $actor, int $unitId): void
    {
        $allowed = $desk->kind === DeviceKind::Meja
            && $desk->isPaired()
            && $actor->is_active
            && $actor->hasRole(User::ROLE_ADMIN_RT)
            && $actor->hasPermissionTo(User::PERMISSION_DESK)
            && $actor->unit_id === $desk->unit_id
            && $desk->unit_id === $unitId;

        if (! $allowed) {
            $this->audit->log('access.denied', meta: ['action' => 'desk', 'desk' => $desk->code()], actor: $actor);

            abort(403, 'Hanya Petugas Meja RT ini dari laptop Meja RT ini.');
        }
    }
}
