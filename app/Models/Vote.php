<?php

namespace App\Models;

use App\Enums\VoteStatus;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Suara. Tanpa timestamp dan ber-UUID acak (bukan berurutan) agar tidak bisa
 * dicocokkan dengan waktu verifikasi PIN. Tidak pernah dihapus; pembatalan mengubah status.
 */
#[Hidden(['voter_link'])]
#[WithoutTimestamps]
class Vote extends Model
{
    use HasUuids;

    /**
     * UUID v4 (acak penuh). UUID v7 bawaan Laravel memuat waktu, jadi tidak dipakai di sini.
     */
    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VoteStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<Ballot, $this>
     */
    public function ballot(): BelongsTo
    {
        return $this->belongsTo(Ballot::class);
    }
}
