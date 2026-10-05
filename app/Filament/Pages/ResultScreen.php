<?php

namespace App\Filament\Pages;

use App\Enums\ElectionStatus;
use App\Enums\StaffRole;
use App\Filament\Pages\Concerns\InteractsWithElection;
use App\Services\AuditLogger;
use App\Services\Voting\ResultsCalculator;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
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

        abort_unless($election->status->allowsResults(), 403);

        $calculator = app(ResultsCalculator::class);
        $output = [];

        foreach ($election->rounds as $round) {
            foreach ($election->ballots as $ballot) {
                $output[] = [
                    'round' => $round->number,
                    'ballot' => $ballot,
                    'tally' => $calculator->tally($ballot, $round),
                ];
            }
        }

        return $output;
    }

    /**
     * @return array<string, mixed>
     */
    public function participation(): array
    {
        $election = $this->authorizedElection();

        return app(ResultsCalculator::class)->participation($election, $election->currentRound());
    }
}
