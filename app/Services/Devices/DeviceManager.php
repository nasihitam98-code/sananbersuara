<?php

namespace App\Services\Devices;

use App\Enums\DeviceKind;
use App\Enums\DeviceReleaseReason;
use App\Enums\ElectionStatus;
use App\Enums\PermitStatus;
use App\Models\Device;
use App\Models\Election;
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

    /**
     * Membuat slot Meja (nomor 0) dan Bilik 1..N untuk setiap RT yang punya pemilih berhak.
     */
    public function ensureSlots(Election $election): void
    {
        $unitIds = $election->ballots()->get()
            ->flatMap(fn ($ballot) => $this->eligibility->eligibleUnitIds($ballot))
            ->unique()
            ->values();

        $booths = (int) $election->setting('max_booths_per_unit');

        foreach ($unitIds as $unitId) {
            foreach ([[DeviceKind::Meja, 0], ...array_map(fn (int $number): array => [DeviceKind::Bilik, $number], range(1, max(1, $booths)))] as [$kind, $number]) {
                $attributes = ['election_id' => $election->id, 'unit_id' => $unitId, 'kind' => $kind, 'number' => $number];

                if (Device::query()->where($attributes)->exists()) {
                    continue;
                }

                (new Device)->forceFill($attributes + ['public_id' => (string) Str::ulid()])->save();
            }
        }
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
