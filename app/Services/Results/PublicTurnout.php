<?php

namespace App\Services\Results;

use App\Enums\BallotScope;
use App\Enums\ElectionStatus;
use App\Models\Election;
use App\Models\Unit;
use App\Services\Voting\ResultsCalculator;
use Illuminate\Support\Facades\Cache;

/**
 * Partisipasi untuk portal publik (hanya pemilihan yang sudah DIPUBLIKASIKAN): berapa warga yang memilih,
 * keseluruhan dan per RT. Tidak memuat angka suara calon (K21). Memakai putaran 1 (partisipasi utama).
 */
class PublicTurnout
{
    public function __construct(private ResultsCalculator $calculator) {}

    /**
     * @return array{voted: int, total: int, percent: float, units: array<int, array{unit: string, voted: int, total: int, percent: float}>}|null
     */
    public function for(Election $election): ?array
    {
        if ($election->status !== ElectionStatus::Published) {
            return null;
        }

        return Cache::remember(
            "public-turnout:{$election->id}:".$election->published_at?->timestamp,
            now()->addMinutes(10),
            fn (): array => $election->isDadakan() ? $this->dadakan($election) : $this->resmi($election),
        );
    }

    /**
     * Partisipasi setiap pemilihan yang sudah diumumkan, urut waktu (untuk grafik riwayat).
     *
     * @return array<int, array{name: string, date: ?string, percent: float}>
     */
    public function history(): array
    {
        return Election::query()
            ->where('status', ElectionStatus::Published)
            ->orderBy('closed_at')
            ->get()
            ->map(fn (Election $election): array => [
                'name' => $election->name,
                'date' => ($election->started_at ?? $election->closed_at)?->translatedFormat('M Y'),
                'percent' => $this->for($election)['percent'] ?? 0.0,
            ])
            ->all();
    }

    /**
     * @return array{voted: int, total: int, percent: float, units: array<int, array{unit: string, voted: int, total: int, percent: float}>}
     */
    private function dadakan(Election $election): array
    {
        $round = $election->rounds()->first();
        $overall = $this->calculator->participation($election, $round);

        return [
            'voted' => $overall['voted'],
            'total' => $overall['attendees'],
            'percent' => $overall['percent'],
            'units' => collect($this->calculator->participationByUnit($election, $round))
                ->map(fn (array $unit): array => ['unit' => $unit['unit'], 'voted' => $unit['voted'], 'total' => $unit['attendees'], 'percent' => $unit['percent']])
                ->all(),
        ];
    }

    /**
     * Mode Resmi: penyebut = pemilih berhak di surat suara yang mencakup semua RT (biasanya Ketua RW).
     *
     * @return array{voted: int, total: int, percent: float, units: array<int, array{unit: string, voted: int, total: int, percent: float}>}
     */
    private function resmi(Election $election): array
    {
        $round = $election->rounds()->first();
        $ballots = $election->ballots()->get();
        $ballot = $ballots->firstWhere('scope', BallotScope::SemuaRt) ?? $ballots->first();

        if ($ballot === null) {
            return ['voted' => 0, 'total' => 0, 'percent' => 0.0, 'units' => []];
        }

        $overall = $this->calculator->ballotParticipation($ballot, $round);

        $units = Unit::query()
            ->orderBy('sort')
            ->get()
            ->map(fn (Unit $unit): array => ['unit' => $unit->name] + $this->calculator->ballotParticipation($ballot, $round, $unit->id))
            ->filter(fn (array $unit): bool => $unit['eligible'] > 0)
            ->map(fn (array $unit): array => ['unit' => $unit['unit'], 'voted' => $unit['voted'], 'total' => $unit['eligible'], 'percent' => $unit['percent']])
            ->values()
            ->all();

        return ['voted' => $overall['voted'], 'total' => $overall['eligible'], 'percent' => $overall['percent'], 'units' => $units];
    }
}
