<?php

namespace App\Services\Voting;

use App\Models\Election;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Status untuk halaman pemilih yang dirender server dan endpoint cadangan /status.
 * Jalur utama polling HP adalah file statis dari StatusPublisher.
 */
class VoterStatus
{
    public const WAITING = 'waiting';

    public const OPEN = 'open';

    public const PAUSED = 'paused';

    public const FINISHED = 'finished';

    public function __construct(
        private WaveManager $waves,
        private StatusPublisher $publisher,
    ) {}

    public static function cacheKey(Election $election): string
    {
        return "voter-status:{$election->id}";
    }

    /**
     * @return array{state: string, wave: ?int, round: ?int, ends_at: ?int, remaining: ?int, assisted: bool}
     */
    public function for(Election $election): array
    {
        $snapshot = Cache::remember(static::cacheKey($election), 2, function () use ($election): array {
            $this->waves->finalizeExpired($election);

            return $this->publisher->build($election);
        });

        $remaining = $snapshot['ends_at'] === null
            ? $snapshot['paused_remaining']
            : max(0, $snapshot['ends_at'] - Carbon::now()->getTimestamp());

        $state = $snapshot['state'];

        if ($state === self::OPEN && $snapshot['ends_at'] !== null && $remaining === 0) {
            $state = self::WAITING;
        }

        return [
            'state' => $state,
            'wave' => $snapshot['wave'],
            'round' => $snapshot['round'],
            'ends_at' => $snapshot['ends_at'],
            'remaining' => $remaining,
            'assisted' => $snapshot['assisted'],
        ];
    }
}
