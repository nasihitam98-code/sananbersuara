<?php

namespace App\Models;

use App\Enums\StaffRole;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\Email\Concerns\InteractsWithEmailAuthentication;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Akun admin. Tidak ada registrasi publik; akun dibuat Super Admin.
 * Kolom is_active dan must_change_password tidak bisa diisi lewat mass assignment.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasEmailAuthentication
{
    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_ADMIN_RT = 'admin_rt';

    public const ROLE_STAFF = 'staf_pemilihan';

    /** Izin Admin RT (K31): kelola data pemilih RT sendiri. */
    public const PERMISSION_MANAGE_VOTERS = 'kelola_pemilih';

    /** Izin Admin RT (K31): bertugas di Meja Izin (hanya dari laptop meja RT sendiri). */
    public const PERMISSION_DESK = 'petugas_meja';

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, InteractsWithEmailAuthentication, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'has_email_authentication' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->roles()->exists();
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function isAdminRt(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN_RT) && $this->unit_id !== null;
    }

    /**
     * Super Admin: semua RT. Admin RT dengan izin kelola pemilih: hanya RT sendiri.
     */
    public function canManageVotersOf(?int $unitId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->isAdminRt()
            && $this->hasPermissionTo(self::PERMISSION_MANAGE_VOTERS)
            && $unitId !== null
            && $this->unit_id === $unitId;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::ROLE_SUPER_ADMIN);
    }

    /**
     * @return HasMany<ElectionStaff, $this>
     */
    public function electionAssignments(): HasMany
    {
        return $this->hasMany(ElectionStaff::class);
    }

    /**
     * Super Admin otomatis berwenang di semua pemilihan.
     */
    public function hasElectionRole(Election $election, StaffRole ...$roles): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->electionAssignments()
            ->where('election_id', $election->id)
            ->whereIn('role', $roles)
            ->exists();
    }
}
