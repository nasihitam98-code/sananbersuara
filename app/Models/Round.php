<?php

namespace App\Models;

use App\Enums\RoundStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Putaran dibuat dan diubah hanya oleh service (tanpa mass assignment).
 */
class Round extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RoundStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Election, $this>
     */
    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    /**
     * @return HasMany<Wave, $this>
     */
    public function waves(): HasMany
    {
        return $this->hasMany(Wave::class)->orderBy('number');
    }

    /**
     * @return HasMany<AttendeeParticipation, $this>
     */
    public function participations(): HasMany
    {
        return $this->hasMany(AttendeeParticipation::class);
    }
}
