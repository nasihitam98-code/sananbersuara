<?php

namespace App\Filament\Pages\Concerns;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\StaffRole;
use App\Models\Election;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Halaman hari H bekerja pada satu pemilihan (dipilih lewat ?pemilihan=...).
 * Hak akses dicek di server pada setiap aksi, bukan hanya dengan menyembunyikan menu.
 */
trait InteractsWithElection
{
    #[Url(as: 'pemilihan')]
    public ?string $electionId = null;

    /**
     * @return array<int, StaffRole>
     */
    abstract protected static function allowedStaffRoles(): array;

    /**
     * @return array<int, ElectionStatus>
     */
    abstract protected static function allowedStatuses(): array;

    /**
     * Mode Dadakan: akses lewat penugasan staf per pemilihan. Halaman yang juga melayani
     * Mode Resmi mengizinkan Admin RT (dibatasi ke RT sendiri oleh halaman itu).
     *
     * @return array<int, ElectionMode>
     */
    protected static function allowedModes(): array
    {
        return [ElectionMode::Dadakan];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->isSuperAdmin()
            || $user->electionAssignments()->whereIn('role', static::allowedStaffRoles())->exists()
            || (in_array(ElectionMode::Resmi, static::allowedModes(), true) && $user->isAdminRt());
    }

    /**
     * @return Collection<int, Election>
     */
    public function availableElections(): Collection
    {
        /** @var User $user */
        $user = auth()->user();
        $allowsResmi = in_array(ElectionMode::Resmi, static::allowedModes(), true);

        return Election::query()
            ->whereIn('mode', static::allowedModes())
            ->whereIn('status', static::allowedStatuses())
            ->when(! $user->isSuperAdmin(), fn (Builder $query) => $query->where(fn (Builder $access) => $access
                ->where(fn (Builder $dadakan) => $dadakan->where('mode', ElectionMode::Dadakan)->whereHas(
                    'staff',
                    fn (Builder $staff) => $staff->where('user_id', $user->id)->whereIn('role', static::allowedStaffRoles()),
                ))
                ->when($allowsResmi && $user->isAdminRt(), fn (Builder $resmi) => $resmi->orWhere('mode', ElectionMode::Resmi))))
            ->latest()
            ->get();
    }

    public function election(): ?Election
    {
        $elections = $this->availableElections();

        if ($this->electionId === null) {
            // Utamakan yang sedang berjalan, lalu yang Siap; baru yang terbaru (mis. arsip).
            $live = $elections->first(fn (Election $election): bool => $election->status->isLive())
                ?? $elections->first(fn (Election $election): bool => $election->status === ElectionStatus::Ready);
            $this->electionId = ($live ?? $elections->first())?->public_id;
        }

        return $elections->firstWhere('public_id', $this->electionId);
    }

    /**
     * Pemilihan terpilih yang wajib ada dan boleh diakses; dipakai di setiap aksi.
     */
    protected function authorizedElection(): Election
    {
        $election = $this->election();
        /** @var User $user */
        $user = auth()->user();

        $allowed = $election !== null && ($election->isDadakan()
            ? $user->hasElectionRole($election, ...static::allowedStaffRoles())
            : $user->isSuperAdmin() || $user->isAdminRt());

        abort_unless($allowed, 403);

        return $election;
    }

    public function selectElection(string $publicId): void
    {
        $this->electionId = $publicId;
    }
}
