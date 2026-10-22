<?php

namespace App\Filament\Pages;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Filament\Pages\Concerns\ShowsVoterProgress;
use App\Filament\Support\Workspace;
use App\Models\Election;
use App\Models\Unit;
use App\Models\User;
use App\Services\Voting\ResultsCalculator;
use App\Services\Voting\RoundResolver;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Status & Partisipasi Mode Resmi (bagian 9 dan 11): jumlah dan persen sudah memilih per surat suara
 * per RT, plus daftar yang belum memilih. Tidak pernah menampilkan angka per kandidat.
 * Super Admin: semua RT. Admin RT: RT sendiri.
 */
class ParticipationPage extends Page
{
    use ShowsVoterProgress;

    protected string $view = 'filament.pages.participation-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Hari H';

    protected static ?string $navigationLabel = 'Partisipasi';

    protected static ?string $title = 'Partisipasi Pemilihan';

    protected static ?string $slug = 'partisipasi';

    protected static ?int $navigationSort = 3;

    /**
     * Super Admin melihat daftar nama di Monitoring (klik kartu RT); petugas Meja Izin di Meja Izin. Menu ini hanya
     * untuk Admin RT tanpa izin meja. Halamannya tetap bisa dibuka lewat alamatnya.
     */
    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        return Workspace::shows(ElectionMode::Resmi) && ! DeskPage::canAccess() && ! ($user instanceof User && $user->isSuperAdmin());
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->isSuperAdmin() || $user->isAdminRt());
    }

    public function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function election(): ?Election
    {
        return Election::query()
            ->where('mode', ElectionMode::Resmi)
            ->whereIn('status', [ElectionStatus::Berlangsung, ElectionStatus::Paused, ElectionStatus::Ditutup, ElectionStatus::Verifikasi, ElectionStatus::Published, ElectionStatus::Unpublished])
            ->latest()
            ->first();
    }

    /**
     * RT yang boleh dilihat pengguna ini.
     *
     * @return Collection<int, Unit>
     */
    public function units(): Collection
    {
        return $this->user()->isSuperAdmin()
            ? Unit::query()->orderBy('sort')->get()
            : Unit::query()->whereKey($this->user()->unit_id)->get();
    }

    /**
     * @return array<int, array{title: string, total: array<string, mixed>, units: array<int, array<string, mixed>>}>
     */
    public function table(): array
    {
        $election = $this->election();

        if ($election === null) {
            return [];
        }

        $round = $election->currentRound();
        $calculator = app(ResultsCalculator::class);
        $units = $this->units();

        return $election->ballots()->get()->map(function ($ballot) use ($round, $calculator, $units): array {
            $perUnit = $units
                ->map(fn (Unit $unit): array => ['unit' => $unit->name, 'unit_id' => $unit->id] + $calculator->ballotParticipation($ballot, app(RoundResolver::class)->roundFor($ballot->election, $ballot, $unit->id) ?? $round, $unit->id))
                ->filter(fn (array $row): bool => $row['eligible'] > 0)
                ->values()
                ->all();

            return [
                'title' => $ballot->title,
                'total' => $this->user()->isSuperAdmin() ? $calculator->ballotParticipation($ballot, app(RoundResolver::class)->roundFor($ballot->election, $ballot, null) ?? $round) : null,
                'units' => $perUnit,
            ];
        })->all();
    }
}
