<?php

namespace App\Models;

use App\Enums\OutcomeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Penetapan hasil oleh panitia. Dibuat dan diubah hanya oleh ResultPublication.
 */
class Outcome extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OutcomeStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ballot, $this>
     */
    public function ballot(): BelongsTo
    {
        return $this->belongsTo(Ballot::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsToMany<Candidate, $this>
     */
    public function candidates(): BelongsToMany
    {
        return $this->belongsToMany(Candidate::class)->orderBy('number');
    }

    /**
     * "Terpilih" untuk satu calon, "Lolos" untuk beberapa calon (mis. penjaringan).
     */
    public function label(): string
    {
        if ($this->status === OutcomeStatus::BelumDitetapkan) {
            return 'Belum ditetapkan';
        }

        return $this->candidates->count() > 1 ? 'Lolos' : 'Terpilih';
    }
}
