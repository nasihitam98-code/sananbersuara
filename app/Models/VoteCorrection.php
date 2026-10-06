<?php

namespace App\Models;

use App\Enums\CorrectionReason;
use App\Enums\CorrectionStatus;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengajuan koreksi suara. Dibuat dan diubah hanya oleh CorrectionService.
 */
#[RouteKey('public_id')]
class VoteCorrection extends Model
{
    use HasUlids;

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CorrectionStatus::class,
            'reason_code' => CorrectionReason::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Voter, $this>
     */
    public function voter(): BelongsTo
    {
        return $this->belongsTo(Voter::class);
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
     * @return BelongsTo<Election, $this>
     */
    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
