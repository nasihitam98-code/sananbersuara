<?php

namespace App\Services\Results;

use App\Enums\BallotScope;
use App\Models\Ballot;
use App\Models\Election;
use App\Models\Unit;
use Illuminate\Support\Collection;

/**
 * "Slot hasil" = satu surat suara (atau satu RT pada surat suara per RT) yang perlu penetapan.
 * "Lingkup berita acara" = per RT (untuk surat suara per RT) atau Keseluruhan (lainnya).
 */
class ResultSlots
{
    /**
     * @return Collection<int, array{key: string, ballot: Ballot, unit: ?Unit}>
     */
    public function slots(Election $election): Collection
    {
        $slots = collect();

        foreach ($election->ballots()->with('candidates.unit')->get() as $ballot) {
            if ($ballot->scope === BallotScope::PerRt) {
                $units = $ballot->candidates->pluck('unit')->filter()->unique('id')->sortBy('sort');

                foreach ($units as $unit) {
                    $slots->push(['key' => static::key($ballot, $unit), 'ballot' => $ballot, 'unit' => $unit]);
                }

                continue;
            }

            $slots->push(['key' => static::key($ballot, null), 'ballot' => $ballot, 'unit' => null]);
        }

        return $slots;
    }

    /**
     * Lingkup berita acara: null = Keseluruhan, Unit = per RT.
     *
     * @return Collection<int, ?Unit>
     */
    public function reportScopes(Election $election): Collection
    {
        $slots = $this->slots($election);
        $scopes = $slots->pluck('unit')->filter()->unique('id')->sortBy('sort')->values();

        if ($slots->contains(fn (array $slot): bool => $slot['unit'] === null)) {
            $scopes->prepend(null);
        }

        return $scopes;
    }

    /**
     * Slot yang masuk satu lingkup berita acara.
     *
     * @return Collection<int, array{key: string, ballot: Ballot, unit: ?Unit}>
     */
    public function slotsInScope(Election $election, ?Unit $scope): Collection
    {
        return $this->slots($election)
            ->filter(fn (array $slot): bool => $scope === null ? $slot['unit'] === null : $slot['unit']?->is($scope) === true)
            ->values();
    }

    public static function key(Ballot $ballot, ?Unit $unit): string
    {
        return $ballot->id.':'.($unit?->id ?? 0);
    }
}
