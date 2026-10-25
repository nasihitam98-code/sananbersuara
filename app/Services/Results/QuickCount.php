<?php

namespace App\Services\Results;

use App\Enums\ElectionStatus;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Unit;
use App\Services\Voting\ResultsCalculator;
use App\Services\Voting\RoundResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Quick count di portal publik (keputusan rapat panitia 17 Okt 2026): perolehan sementara tiap calon dan partisipasi
 * selama voting sampai hasil resmi diumumkan. Angka dipotret tiap 10 menit (bukan tiap suara masuk) agar pilihan
 * seseorang tidak bisa ditebak dari angka yang naik tepat setelah ia keluar bilik. Hanya bila saklarnya dinyalakan
 * Super Admin dan pemilihan tampil di halaman publik.
 */
class QuickCount
{
    public const INTERVAL_MINUTES = 10;

    /** @var array<int, ElectionStatus> */
    public const STATUSES = [ElectionStatus::Berlangsung, ElectionStatus::Paused, ElectionStatus::Ditutup, ElectionStatus::Verifikasi];

    public function __construct(
        private ResultSlots $slots,
        private ResultsCalculator $calculator,
        private RoundResolver $rounds,
        private PublicTurnout $turnout,
    ) {}

    public function enabled(Election $election): bool
    {
        return $election->isPublic()
            && (bool) $election->setting('public_quick_count')
            && in_array($election->status, self::STATUSES, true);
    }

    /**
     * Pemilihan yang quick count-nya tampil di beranda publik (yang terbaru), atau null.
     */
    public function current(): ?Election
    {
        return Election::query()
            ->whereIn('status', self::STATUSES)
            ->latest('started_at')
            ->get()
            ->first(fn (Election $election): bool => $this->enabled($election));
    }

    /**
     * Potret terakhir: kartu per jabatan/RT (bentuk sama dengan kartu hasil resmi, tanpa yang ditetapkan) dan partisipasi.
     *
     * @return array{at: Carbon, final: bool, cards: Collection<int, array<string, mixed>>, turnout: array{voted: int, total: int, percent: float, units: array<int, array{unit: string, voted: int, total: int, percent: float}>}}
     */
    public function snapshot(Election $election): array
    {
        $bucket = intdiv(now()->timestamp, self::INTERVAL_MINUTES * 60);
        // Status ikut kunci: begitu voting ditutup, angka akhir langsung tampil tanpa menunggu potret berikutnya.
        $data = Cache::remember(
            // Waktu Mulai ikut kunci: setelah "Ulang dari awal" lalu dimulai lagi, angka gladi lama tidak ikut terpakai.
            "quick-count:{$election->id}:{$election->started_at?->timestamp}:{$election->status->value}:{$bucket}",
            now()->addMinutes(self::INTERVAL_MINUTES + 5),
            fn (): array => $this->capture($election),
        );

        return [
            'at' => Carbon::createFromTimestamp($data['at']),
            'final' => ! $election->status->isLive(),
            // Tombol "Detail" (visi & misi) mengikuti saklarnya saat ini, bukan saat angka dipotret.
            'cards' => $this->hydrate($data['cards'])->map(fn (array $card): array => $card + ['profiles' => (bool) $election->setting('public_profiles')]),
            'turnout' => $data['turnout'],
        ];
    }

    /**
     * Angka mentah (tanpa model) agar aman disimpan di cache.
     *
     * @return array{at: int, cards: array<int, array<string, mixed>>, turnout: array<string, mixed>}
     */
    private function capture(Election $election): array
    {
        $fallbackRound = $election->currentRound();

        $cards = $this->slots->slots($election)
            ->map(function (array $slot) use ($election, $fallbackRound): array {
                $round = $this->rounds->roundFor($election, $slot['ballot'], $slot['unit']?->id) ?? $fallbackRound;
                $tally = $round === null ? ['valid' => 0, 'candidates' => []] : $this->calculator->tally($slot['ballot'], $round, $slot['unit']);

                return [
                    'office' => $slot['ballot']->title,
                    'unit_id' => $slot['unit']?->id,
                    'valid' => $tally['valid'],
                    'rows' => collect($tally['candidates'])
                        ->map(fn (array $row): array => ['candidate_id' => $row['candidate']->id, 'votes' => $row['votes'], 'percent' => $row['percent'], 'rank' => $row['rank']])
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return ['at' => now()->timestamp, 'cards' => $cards, 'turnout' => $this->turnout->compute($election)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     * @return Collection<int, array<string, mixed>>
     */
    private function hydrate(array $cards): Collection
    {
        $candidates = Candidate::query()
            ->whereIn('id', collect($cards)->flatMap(fn (array $card): array => array_column($card['rows'], 'candidate_id')))
            ->get()
            ->keyBy('id');
        $units = Unit::query()->whereIn('id', collect($cards)->pluck('unit_id')->filter())->get()->keyBy('id');

        return collect($cards)->map(function (array $card) use ($candidates, $units): array {
            $unit = $card['unit_id'] === null ? null : $units->get($card['unit_id']);

            return [
                'title' => $card['office'].($unit !== null ? ' '.$unit->name : ''),
                'office' => $card['office'],
                'unit' => $unit,
                'label' => 'Sementara',
                'decided' => false,
                'quick' => true,
                'candidates' => collect(),
                'tally' => [
                    'valid' => $card['valid'],
                    'rows' => collect($card['rows'])
                        ->filter(fn (array $row): bool => $candidates->has($row['candidate_id']))
                        ->map(fn (array $row): array => [
                            'candidate' => $candidates->get($row['candidate_id']),
                            'votes' => $row['votes'],
                            'percent' => $row['percent'],
                            'rank' => $row['rank'],
                            'elected' => false,
                        ])
                        ->values()
                        ->all(),
                ],
            ];
        });
    }
}
