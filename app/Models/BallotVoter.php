<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hak pilih seorang pemilih pada satu surat suara (snapshot saat pemilihan dimulai).
 */
class BallotVoter extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'added_during_live' => 'boolean',
            'revoked_at' => 'datetime',
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
     * @return BelongsTo<Voter, $this>
     */
    public function voter(): BelongsTo
    {
        return $this->belongsTo(Voter::class);
    }
}
