<?php

namespace App\Models;

use App\Enums\BallotScope;
use App\Enums\CandidateStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'scope', 'sort', 'max_candidates'])]
#[RouteKey('public_id')]
class Ballot extends Model
{
    use HasFactory, HasUlids;

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
            'scope' => BallotScope::class,
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
     * @return HasMany<Candidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class)->orderBy('number');
    }

    /**
     * Kandidat yang tampil di surat suara (urut nomor tetap). Kandidat mundur tetap tampil dengan label.
     *
     * @return HasMany<Candidate, $this>
     */
    public function ballotCandidates(): HasMany
    {
        return $this->candidates()->whereIn('status', [CandidateStatus::Aktif, CandidateStatus::Mundur]);
    }

    /**
     * @return BelongsToMany<Unit, $this>
     */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class);
    }
}
