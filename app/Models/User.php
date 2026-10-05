<?php

namespace App\Models;

use App\Enums\StaffRole;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Akun admin. Tidak ada registrasi publik; akun dibuat Super Admin.
 * Kolom is_active dan must_change_password tidak bisa diisi lewat mass assignment.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_ADMIN_RT = 'admin_rt';

    public const ROLE_STAFF = 'staf_pemilihan';

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

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
            'last_login_at' => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->roles()->exists();
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
