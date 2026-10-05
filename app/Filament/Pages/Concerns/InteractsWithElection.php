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

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->isSuperAdmin() || $user->electionAssignments()->whereIn('role', static::allowedStaffRoles())->exists();
    }

    /**
     * @return Collection<int, Election>
     */
    public function availableElections(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        return Election::query()
            ->where('mode', ElectionMode::Dadakan)
            ->whereIn('status', static::allowedStatuses())
            ->when(! $user->isSuperAdmin(), fn (Builder $query) => $query->whereHas(
                'staff',
                fn (Builder $staff) => $staff->where('user_id', $user->id)->whereIn('role', static::allowedStaffRoles()),
            ))
            ->latest()
            ->get();
    }

    public function election(): ?Election
    {
        $elections = $this->availableElections();

        if ($this->electionId === null) {
            $live = $elections->first(fn (Election $election): bool => $election->status->isLive());
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

        abort_if($election === null || ! $user->hasElectionRole($election, ...static::allowedStaffRoles()), 403);

        return $election;
    }

    public function selectElection(string $publicId): void
    {
        $this->electionId = $publicId;
    }
}
