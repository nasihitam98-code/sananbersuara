<?php

namespace App\Services\Voting;

use App\Enums\ElectionStatus;
use App\Enums\RestoreReason;
use App\Models\Attendee;
use App\Models\Election;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pendataan peserta di pintu (Mode Dadakan). PIN dicetak di struk dan disimpan terenkripsi agar bisa ditampilkan
 * dan dicetak ulang panitia sampai hasil diumumkan.
 */
class AttendeeRegistrar
{
    public function __construct(
        private PinService $pins,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array{attendee: Attendee, pin: string}
     */
    public function register(Election $election, string $name, ?int $unitId, User $actor): array
    {
        if (! $election->isDadakan()) {
            throw VotingException::invalidState('Pendataan di pintu hanya untuk Mode Dadakan.');
        }

        if (! in_array($election->status->value, ['READY', 'BERLANGSUNG', 'PAUSED'], true)) {
            throw VotingException::invalidState('Pendataan hanya bisa saat pemilihan Siap atau Berlangsung.');
        }

        $pin = $this->pins->generate();
        $name = self::tidyName($name);

        $attendee = DB::transaction(function () use ($election, $name, $unitId, $actor, $pin): Attendee {
            Election::query()->whereKey($election->id)->lockForUpdate()->first();

            $attendee = new Attendee(['name' => $name, 'unit_id' => $unitId]);
            $attendee->election()->associate($election);
            $attendee->seq_no = (int) Attendee::query()->where('election_id', $election->id)->max('seq_no') + 1;
            $attendee->public_id = $attendee->newUniqueId();
            $attendee->pin_hash = $this->pins->hash($attendee->public_id, $pin);
            $attendee->pin_encrypted = $pin;
            $attendee->pin_issued_at = Carbon::now();
            $attendee->is_late = $election->openWave() !== null || $election->rounds()->whereHas('waves')->exists();
            $attendee->created_by = $actor->id;
            $attendee->save();

            return $attendee;
        });

        $this->audit->log('attendee.registered', $attendee, $election, meta: [
            'seq_no' => $attendee->seq_no,
            'is_late' => $attendee->is_late,
        ], actor: $actor);

        return ['attendee' => $attendee, 'pin' => $pin];
    }

    /**
     * Rapikan nama yang diketik petugas: spasi berlebih dibuang; bila diketik huruf kecil semua atau huruf besar semua
     * ("budi santoso", "BUDI SANTOSO") jadi "Budi Santoso". Nama dengan huruf campuran (mis. "H. Abdul McLean")
     * dibiarkan karena dianggap sengaja.
     */
    public static function tidyName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === mb_strtolower($name) || $name === mb_strtoupper($name)) {
            return mb_convert_case(mb_strtolower($name), MB_CASE_TITLE, 'UTF-8');
        }

        return $name;
    }

    /**
     * Hapus orang dari daftar hadir (salah ketik, terdaftar dua kali). Hanya yang belum pernah tercatat memilih
     * (termasuk yang sudah dibatalkan lewat Pulihkan), agar riwayat suara tetap utuh. Tercatat di audit beserta alasan.
     */
    public function delete(Election $election, Attendee $attendee, User $actor, string $reason): void
    {
        if ($attendee->election_id !== $election->id) {
            throw VotingException::invalidChoice();
        }

        if (! in_array($election->status, [ElectionStatus::Ready, ElectionStatus::Berlangsung, ElectionStatus::Paused], true)) {
            throw VotingException::invalidState('Daftar hadir hanya bisa diubah saat pemilihan Siap atau Berlangsung.');
        }

        DB::transaction(function () use ($election, $attendee, $actor, $reason): void {
            $locked = Attendee::query()->whereKey($attendee->id)->lockForUpdate()->firstOrFail();

            if ($locked->participations()->exists()) {
                throw VotingException::invalidState("{$locked->name} sudah tercatat memilih, jadi tidak bisa dihapus.");
            }

            $this->audit->log('attendee.deleted', $locked, $election, note: $reason, meta: [
                'seq_no' => $locked->seq_no,
                'name' => $locked->name,
            ], actor: $actor);

            $locked->delete();
        });
    }

    /**
     * Cetak ulang struk (kertas habis, struk rusak/hilang): PIN yang sama bila masih tersimpan. Bila tidak tersimpan
     * (didata sebelum PIN disimpan), dibuat PIN baru dan PIN lama hangus, hanya untuk yang belum memilih.
     *
     * @return array{pin: string, is_new: bool}
     */
    public function reprint(Election $election, Attendee $attendee, User $actor): array
    {
        if ($attendee->election_id !== $election->id) {
            throw VotingException::invalidChoice();
        }

        $stored = $attendee->storedPin();

        if ($stored !== null) {
            $this->audit->log('attendee.pin_reprinted', $attendee, $election, meta: ['seq_no' => $attendee->seq_no, 'same_pin' => true], actor: $actor);

            return ['pin' => $stored, 'is_new' => false];
        }

        return ['pin' => $this->reprintWithNewPin($election, $attendee, $actor), 'is_new' => true];
    }

    /**
     * Cetak ulang dengan PIN baru (PIN lama hangus). Hanya untuk yang belum memilih.
     */
    public function reprintWithNewPin(Election $election, Attendee $attendee, User $actor): string
    {
        if ($attendee->election_id !== $election->id) {
            throw VotingException::invalidChoice();
        }

        if ($election->status->isLive()) {
            // Saat berlangsung lewat jalur Pulihkan (kunci baris, cek sudah memilih, audit beralasan).
            return app(VoterRightRestorer::class)->restore($election, $attendee, RestoreReason::PinHilang, 'Cetak ulang dari Meja Pintu', $actor, onlyIfNotVoted: true)['pin'];
        }

        if ($election->status !== ElectionStatus::Ready) {
            throw VotingException::invalidState('Cetak ulang hanya bisa saat pemilihan Siap atau Berlangsung.');
        }

        // Status Siap: voting belum pernah dibuka, jadi belum ada yang memilih.
        $pin = $this->reissuePin($attendee);
        $this->audit->log('attendee.pin_reprinted', $attendee, $election, meta: ['seq_no' => $attendee->seq_no, 'same_pin' => false], actor: $actor);

        return $pin;
    }

    /**
     * Membuat PIN baru (PIN lama hangus) dan membuka kunci. Dipanggil oleh VoterRightRestorer.
     */
    public function reissuePin(Attendee $attendee): string
    {
        $pin = $this->pins->generate();

        $attendee->forceFill([
            'pin_hash' => $this->pins->hash($attendee->public_id, $pin),
            'pin_encrypted' => $pin,
            'pin_failed_attempts' => 0,
            'pin_locked_at' => null,
            'pin_issued_at' => Carbon::now(),
        ])->save();

        return $pin;
    }
}
