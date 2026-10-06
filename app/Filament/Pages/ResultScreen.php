<?php

namespace App\Filament\Pages;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\StaffRole;
use App\Filament\Pages\Concerns\InteractsWithElection;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Results\ResultSlots;
use App\Services\Voting\ResultsCalculator;
use App\Services\Voting\RoundResolver;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use UnitEnum;

/**
 * Layar Hasil (bagian 9): hanya setelah pemilihan DITUTUP. Hasil per kandidat ditampilkan
 * sekaligus lewat tombol [Tampilkan Hasil] yang tercatat di audit log.
 * Sistem tidak menetapkan pemenang/yang lolos; itu keputusan panitia.
 */
class ResultScreen extends Page
{
    use InteractsWithElection;

    protected string $view = 'filament.pages.result-screen';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Hari H';

    protected static ?string $navigationLabel = 'Layar Hasil';

    protected static ?string $title = 'Hasil Pemungutan Suara';

    protected static ?string $slug = 'layar-hasil';

    protected static ?int $navigationSort = 3;

    /** Hanya bisa diubah lewat aksi server (tercatat di audit), tidak dari browser. */
    #[Locked]
    public bool $revealed = false;

    protected static function allowedStaffRoles(): array
    {
        return [StaffRole::Panitia];
    }

    protected static function allowedStatuses(): array
    {
        return [ElectionStatus::Ditutup, ElectionStatus::Verifikasi, ElectionStatus::Published, ElectionStatus::Unpublished];
    }

    public function updatedElectionId(): void
    {
        $this->revealed = false;
    }

    public function selectElection(string $publicId): void
    {
        $this->electionId = $publicId;
        $this->revealed = false;
    }

    public function reveal(): void
    {
        $election = $this->authorizedElection();

        abort_unless($election->status->allowsResults(), 403);

        if ($election->results_revealed_at === null) {
            $election->forceFill(['results_revealed_at' => Carbon::now()])->save();
        }

        app(AuditLogger::class)->log('results.revealed', $election, $election);

        $this->revealed = true;
    }

    public function hide(): void
    {
        $this->revealed = false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function results(): array
    {
        $election = $this->authorizedElection();

        abort_unless($election->status->allowsResults() && $this->revealed, 403);

        $calculator = app(ResultsCalculator::class);
        /** @var User $user */
        $user = auth()->user();
        $output = [];

        // Admin RT (Mode Resmi): hanya surat suara RT-nya sendiri + total surat suara semua RT (K19).
        $slots = app(ResultSlots::class)->slots($election)
            ->filter(fn (array $slot): bool => $user->isSuperAdmin() || $election->isDadakan() || $slot['unit'] === null || $slot['unit']->id === $user->unit_id);

        $resolver = app(RoundResolver::class);

        foreach ($election->rounds as $round) {
            foreach ($slots as $slot) {
                if (! $resolver->covers($round, $slot['ballot']->id, $slot['unit']?->id)) {
                    continue;
                }

                $output[] = [
                    'round' => $round->number,
                    'ballot' => $slot['ballot'],
                    'title' => $slot['ballot']->title.($slot['unit'] !== null ? ' — '.$slot['unit']->name : ''),
                    'tally' => $calculator->tally($slot['ballot'], $round, $slot['unit']),
                ];
            }
        }

        return $output;
    }

    /**
     * Partisipasi Mode Dadakan (Mode Resmi memakai halaman Partisipasi per RT).
     *
     * @return array<string, mixed>|null
     */
    public function participation(): ?array
    {
        $election = $this->authorizedElection();

        return $election->isDadakan()
            ? app(ResultsCalculator::class)->participation($election, $election->currentRound())
            : null;
    }

    /**
     * @return array<int, ElectionMode>
     */
    protected static function allowedModes(): array
    {
        return [ElectionMode::Dadakan, ElectionMode::Resmi];
    }
}
