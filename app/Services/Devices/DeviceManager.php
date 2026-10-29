<?php

namespace App\Services\Devices;

use App\Enums\DeviceKind;
use App\Enums\DeviceReleaseReason;
use App\Enums\ElectionStatus;
use App\Enums\PermitStatus;
use App\Models\Device;
use App\Models\Election;
use App\Models\Permit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Permits\PermitManager;
use App\Services\Voters\EligibilitySnapshot;
use App\Services\Voting\VotingException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Perangkat Meja/Bilik (bagian 5.2–5.4, 10A-B):
 * token acak sekali pakai (disimpan hash, tampil sekali, ada masa berlaku, terikat satu slot),
 * sesi perangkat lewat cookie terenkripsi + hash rahasia di server, dan pelepasan yang langsung mencabut akses.
 */
class DeviceManager
{
    public const COOKIE = 'rtrw_device';

    private const TOKEN_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private AuditLogger $audit,
        private EligibilitySnapshot $eligibility,
    ) {}

    /** Batas bilik per RT yang bisa ditambah dari Meja Izin. */
    public const MAX_BOOTHS_PER_UNIT = 10;

    /**
     * Membuat slot Meja (nomor 0) untuk setiap RT yang punya pemilih berhak, dan Bilik 1..N (bawaan pengaturan) hanya
     * bila RT itu belum punya bilik sama sekali. Setelah itu jumlah bilik diatur tiap RT sendiri (tambah/hapus di
     * Meja Izin, keputusan pemilik 2026-10-25), jadi bilik yang dihapus tidak dibuat ulang.
     */
    public function ensureSlots(Election $election): void
    {
        $unitIds = $election->ballots()->get()
            ->flatMap(fn ($ballot) => $this->eligibility->eligibleUnitIds($ballot))
            ->unique()
            ->values();

        $booths = max(1, (int) $election->setting('max_booths_per_unit'));

        foreach ($unitIds as $unitId) {
            $existing = Device::query()->where('election_id', $election->id)->where('unit_id', $unitId)->get(['kind', 'number']);
            $slots = $existing->contains('kind', DeviceKind::Meja) ? [] : [[DeviceKind::Meja, 0]];

            if (! $existing->contains('kind', DeviceKind::Bilik)) {
                $slots = [...$slots, ...array_map(fn (int $number): array => [DeviceKind::Bilik, $number], range(1, $booths))];
            }

            foreach ($slots as [$kind, $number]) {
                (new Device)->forceFill([
                    'election_id' => $election->id,
                    'unit_id' => $unitId,
                    'kind' => $kind,
                    'number' => $number,
                    'public_id' => (string) Str::ulid(),
                ])->save();
            }
        }
    }

    /**
     * Tambah satu bilik untuk RT ini (nomor berikutnya). Mengembalikan slot baru.
     */
    public function addBooth(Election $election, int $unitId, User $actor): Device
    {
        $current = Device::query()->where('election_id', $election->id)->where('unit_id', $unitId)->where('kind', DeviceKind::Bilik);

        if ((clone $current)->count() >= self::MAX_BOOTHS_PER_UNIT) {
            throw VotingException::invalidState('Maksimal '.self::MAX_BOOTHS_PER_UNIT.' bilik per RT.');
        }

        $device = new Device;
        $device->forceFill([
            'election_id' => $election->id,
            'unit_id' => $unitId,
            'kind' => DeviceKind::Bilik,
            'number' => ((int) (clone $current)->max('number')) + 1,
            'public_id' => (string) Str::ulid(),
        ])->save();

        $this->audit->log('device.booth_added', $device, $election, meta: ['booth' => $device->code()], actor: $actor);

        return $device;
    }

    /**
     * Hapus bilik yang belum pernah dipakai dan sedang tidak terpasang. Bilik yang sudah pernah dipakai tetap ada
     * (riwayat izin merujuk ke sana); cukup dilepas.
     */
    public function removeBooth(Device $device, User $actor): void
    {
        if ($device->kind !== DeviceKind::Bilik || $device->isPaired() || Permit::query()->where('device_id', $device->id)->exists()) {
            throw VotingException::invalidState('Hanya bilik yang belum terpasang dan belum pernah dipakai yang bisa dihapus.');
        }

        if (Device::query()->where('election_id', $device->election_id)->where('unit_id', $device->unit_id)->where('kind', DeviceKind::Bilik)->count() <= 1) {
            throw VotingException::invalidState('Minimal satu bilik per RT.');
        }

        $this->audit->log('device.booth_removed', $device, $device->election, meta: ['booth' => $device->code()], actor: $actor);
        $device->delete();
    }

    /**
     * Bilik ini boleh dihapus (untuk menampilkan tombolnya).
     */
    public function canRemoveBooth(Device $device): bool
    {
        return $device->kind === DeviceKind::Bilik
            && ! $device->isPaired()
            && ! Permit::query()->where('device_id', $device->id)->exists()
            && Device::query()->where('election_id', $device->election_id)->where('unit_id', $device->unit_id)->where('kind', DeviceKind::Bilik)->count() > 1;
    }

    /**
     * Admin RT memasang laptop yang sedang dipakainya sebagai Meja RT-nya sendiri, tanpa menunggu token dari Super Admin
     * (keputusan pemilik 2026-10-29; identitas dipastikan lewat login + password ulang). Laptop Meja lama RT itu terlepas.
     *
     * @return array{device: Device, secret: string}
     */
    public function pairOwnDesk(Election $election, User $actor, string $label, Request $request): array
    {
        abort_unless($actor->is_active && $actor->hasRole(User::ROLE_ADMIN_RT) && $actor->hasPermissionTo(User::PERMISSION_DESK) && $actor->unit_id !== null, 403);

        $desk = Device::query()
            ->where('election_id', $election->id)
            ->where('unit_id', $actor->unit_id)
            ->where('kind', DeviceKind::Meja)
            ->first();

        if ($desk === null) {
            throw VotingException::invalidState('Meja RT ini belum disiapkan. Pemilihan harus berstatus Siap atau Berlangsung.');
        }

        $result = $this->pair($this->issueToken($desk, $actor), $label, $request, DeviceKind::Meja);

        $this->audit->log('device.desk_self_paired', $result['device'], $election, meta: ['device' => $result['device']->code(), 'label' => $result['device']->label], actor: $actor);

        return $result;
    }

    /**
     * Token baru membuat token lama slot ini hangus. Mengembalikan token (tampil sekali).
     */
    public function issueToken(Device $device, User $actor): string
    {
        $election = $device->election;

        if (! in_array($election->status, [ElectionStatus::Ready, ElectionStatus::Berlangsung, ElectionStatus::Paused], true)) {
            throw VotingException::invalidState('Token perangkat hanya bisa dibuat saat pemilihan Siap atau Berlangsung.');
        }

        $isDesk = $device->kind === DeviceKind::Meja;
        $length = $isDesk ? 12 : 8;
        $token = collect(range(1, $length))->map(fn (): string => self::TOKEN_ALPHABET[random_int(0, strlen(self::TOKEN_ALPHABET) - 1)])->implode('');
        $expiresAt = $isDesk
            ? Carbon::now()->addHours((int) $election->setting('desk_token_hours'))
            : Carbon::now()->addMinutes((int) $election->setting('booth_token_minutes'));

        $device->forceFill([
            'pairing_token_hash' => static::hashToken($token),
            'pairing_token_expires_at' => $expiresAt,
            // Terenkripsi, agar token tetap tampil di Meja/Perangkat sampai dipakai atau kedaluwarsa.
            'pairing_token_encrypted' => implode('-', str_split($token, 4)),
        ])->save();

        $this->audit->log('device.token_issued', $device, $election, meta: [
            'device' => $device->code(),
            'expires_at' => $expiresAt->toIso8601String(),
        ], actor: $actor);

        return implode('-', str_split($token, 4));
    }

    /**
     * Memasang laptop ke slot. Mengembalikan rahasia sesi yang disimpan di cookie perangkat.
     *
     * @return array{device: Device, secret: string}
     */
    public function pair(#[SensitiveParameter] string $token, string $label, Request $request, DeviceKind $kind): array
    {
        $hash = static::hashToken($token);

        $result = DB::transaction(function () use ($hash, $label, $request, $kind): ?array {
            $device = Device::query()->where('pairing_token_hash', $hash)->where('kind', $kind)->lockForUpdate()->first();

            if ($device === null || $device->pairing_token_expires_at?->isPast() !== false) {
                return null;
            }

            if (! in_array($device->election->status, [ElectionStatus::Ready, ElectionStatus::Berlangsung, ElectionStatus::Paused], true)) {
                return null;
            }

            $replacedAnotherLaptop = $device->isPaired();
            $secret = Str::random(48);

            $device->forceFill([
                'pairing_token_hash' => null,
                'pairing_token_expires_at' => null,
                'pairing_token_encrypted' => null,
                'session_secret_hash' => hash('sha256', $secret),
                'label' => Str::of($label)->squish()->limit(120, '')->toString() ?: null,
                'paired_at' => Carbon::now(),
                'last_seen_at' => Carbon::now(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
                'ip_address' => $request->ip(),
                'released_at' => null,
                'release_reason' => null,
            ])->save();

            return ['device' => $device, 'secret' => $secret, 'replaced' => $replacedAnotherLaptop];
        });

        if ($result === null) {
            $this->audit->log('device.token_rejected', meta: ['ip' => $request->ip()], actorType: 'device');

            throw VotingException::invalidState('Token salah, sudah dipakai, atau kedaluwarsa. Minta token baru ke petugas.');
        }

        $this->audit->log('device.paired', $result['device'], $result['device']->election, meta: [
            'device' => $result['device']->code(),
            'label' => $result['device']->label,
            'replaced_previous_laptop' => $result['replaced'],
        ], actorType: 'device');

        return ['device' => $result['device'], 'secret' => $result['secret']];
    }

    /**
     * Perangkat dari cookie, hanya jika rahasianya cocok, belum dilepas, dan pemilihan belum selesai.
     */
    public function authenticate(Request $request, ?DeviceKind $kind = null): ?Device
    {
        $raw = (string) $request->cookie(self::COOKIE);

        if (! str_contains($raw, '|')) {
            return null;
        }

        [$publicId, $secret] = explode('|', $raw, 2);

        $device = Device::query()->with(['election', 'unit'])->where('public_id', $publicId)->first();

        if ($device === null || $device->session_secret_hash === null || ! hash_equals($device->session_secret_hash, hash('sha256', $secret))) {
            return null;
        }

        if ($kind !== null && $device->kind !== $kind) {
            return null;
        }

        if (! in_array($device->election->status, [ElectionStatus::Ready, ElectionStatus::Berlangsung, ElectionStatus::Paused], true)) {
            return null;
        }

        return $device;
    }

    public function heartbeat(Device $device, Request $request): void
    {
        if ($device->last_seen_at !== null && $device->last_seen_at->greaterThan(Carbon::now()->subSeconds(5))) {
            return;
        }

        $device->forceFill(['last_seen_at' => Carbon::now(), 'ip_address' => $request->ip()])->saveQuietly();
    }

    /**
     * Melepas laptop dari slot: akses langsung dicabut, izin aktif di bilik itu dihentikan.
     */
    public function release(Device $device, DeviceReleaseReason $reason, ?User $actor): void
    {
        DB::transaction(function () use ($device, $reason, $actor): void {
            $permit = $device->activePermit()->lockForUpdate()->first();

            if ($permit !== null) {
                app(PermitManager::class)->end($permit, PermitStatus::Terhenti, 'BILIK_DILEPAS', $actor);
            }

            $device->forceFill([
                'session_secret_hash' => null,
                'pairing_token_hash' => null,
                'pairing_token_expires_at' => null,
                'pairing_token_encrypted' => null,
                'released_at' => Carbon::now(),
                'release_reason' => $reason,
            ])->save();
        });

        $this->audit->log('device.released', $device, $device->election, reasonCode: $reason->value, meta: ['device' => $device->code()], actor: $actor, actorType: $actor === null ? 'system' : 'user');
    }

    public function releaseAll(Election $election): void
    {
        Device::query()
            ->where('election_id', $election->id)
            ->whereNotNull('session_secret_hash')
            ->get()
            ->each(fn (Device $device) => $this->release($device, DeviceReleaseReason::PemilihanDitutup, null));
    }

    public function cookieFor(Device $device, #[SensitiveParameter] string $secret): SymfonyCookie
    {
        return Cookie::make(self::COOKIE, $device->public_id.'|'.$secret, 60 * 24 * 3, httpOnly: true, sameSite: 'lax');
    }

    public static function hashToken(#[SensitiveParameter] string $token): string
    {
        $normalized = preg_replace('/[^A-Z0-9]/', '', Str::upper($token));

        return hash_hmac('sha256', 'device-token|'.$normalized, (string) config('app.key'));
    }
}
