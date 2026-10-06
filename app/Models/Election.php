<?php

namespace App\Models;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\RoundStatus;
use App\Enums\StaffRole;
use App\Enums\WaveStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Status tidak bisa diisi lewat mass assignment; perubahan status hanya lewat ElectionLifecycle.
 */
#[Fillable(['name', 'mode', 'settings'])]
#[RouteKey('public_id')]
class Election extends Model
{
    use HasFactory, HasUlids;

    protected $attributes = [
        'status' => 'DRAFT',
    ];

    protected static function booted(): void
    {
        static::creating(function (Election $election): void {
            $election->access_code ??= static::generateAccessCode();
        });
    }

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public static function generateAccessCode(): string
    {
        return Str::lower(Str::random(12));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ElectionMode::class,
            'status' => ElectionStatus::class,
            'settings' => 'array',
            'started_at' => 'datetime',
            'closed_at' => 'datetime',
            'published_at' => 'datetime',
            'personal_data_purged_at' => 'datetime',
            'results_revealed_at' => 'datetime',
            'vote_links_destroyed_at' => 'datetime',
        ];
    }

    /**
     * Nilai pengaturan pemilihan, jatuh ke default di config/voting.php.
     */
    public function setting(string $key): mixed
    {
        return data_get($this->settings, $key, config("voting.defaults.{$key}"));
    }

    public function isDadakan(): bool
    {
        return $this->mode === ElectionMode::Dadakan;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<Ballot, $this>
     */
    public function ballots(): HasMany
    {
        return $this->hasMany(Ballot::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @return HasMany<Round, $this>
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class)->orderBy('number');
    }

    /**
     * @return HasMany<Attendee, $this>
     */
    public function attendees(): HasMany
    {
        return $this->hasMany(Attendee::class);
    }

    /**
     * @return HasMany<ElectionStaff, $this>
     */
    public function staff(): HasMany
    {
        return $this->hasMany(ElectionStaff::class);
    }

    /**
     * @return HasMany<Vote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    public function currentRound(): ?Round
    {
        return $this->rounds()->where('status', RoundStatus::Dibuka)->latest('number')->first()
            ?? $this->rounds()->latest('number')->first();
    }

    public function openWave(): ?Wave
    {
        $round = $this->currentRound();

        if ($round === null) {
            return null;
        }

        return $round->waves()
            ->whereIn('status', [WaveStatus::Dibuka, WaveStatus::Dijeda])
            ->latest('number')
            ->first();
    }

    public function hasStaff(User $user, StaffRole $role): bool
    {
        return $this->staff()->where('user_id', $user->id)->where('role', $role)->exists();
    }

    /**
     * Surat suara bercalon banyak (mis. penjaringan) memakai durasi gelombang lebih panjang.
     */
    public function defaultWaveMinutes(): int
    {
        $maxCandidates = $this->ballots()->withCount('candidates')->get()->max('candidates_count') ?? 0;

        return $maxCandidates > (int) $this->setting('many_candidates_threshold')
            ? (int) $this->setting('wave_minutes_many_candidates')
            : (int) $this->setting('wave_minutes');
    }
}
