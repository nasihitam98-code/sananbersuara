<?php

namespace App\Filament\Pages;

use App\Enums\ElectionStatus;
use App\Enums\OutcomeStatus;
use App\Enums\ReportStatus;
use App\Filament\Support\InElectionMenu;
use App\Filament\Support\Reauthenticate;
use App\Filament\Support\Workspace;
use App\Models\Ballot;
use App\Models\Election;
use App\Models\OfficialReport;
use App\Models\Unit;
use App\Models\User;
use App\Services\Results\DataRetention;
use App\Services\Results\OfficialReportService;
use App\Services\Results\ResultPublication;
use App\Services\Results\ResultSlots;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\NextRoundService;
use App\Services\Voting\ResultsCalculator;
use App\Services\Voting\RoundResolver;
use App\Services\Voting\VotingException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Verifikasi & Publikasi (Super Admin): penetapan hasil per surat suara/RT (K21),
 * berita acara draf -> disahkan (K08), lalu Publish / Unpublish.
 */
class VerificationDesk extends Page
{
    use InElectionMenu;

    public const ELECTION_MENU_SORT = 6;

    protected string $view = 'filament.pages.verification-desk';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Hasil';

    protected static ?string $navigationLabel = 'Verifikasi & Publikasi';

    protected static ?string $title = 'Verifikasi & Publikasi Hasil';

    protected static ?string $slug = 'verifikasi-publikasi';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        return Workspace::showsResultsMenu();
    }

    #[Url(as: 'pemilihan')]
    public ?string $electionId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    /**
     * @return Collection<int, Election>
     */
    public function availableElections(): Collection
    {
        return Election::query()
            ->whereIn('status', [ElectionStatus::Ditutup, ElectionStatus::Verifikasi, ElectionStatus::Published, ElectionStatus::Unpublished])
            ->latest()
            ->get();
    }

    public function election(): ?Election
    {
        $elections = $this->availableElections();
        $this->electionId ??= $elections->first()?->public_id;

        return $elections->firstWhere('public_id', $this->electionId);
    }

    public function selectElection(string $publicId): void
    {
        $this->electionId = $publicId;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function slotRows(): array
    {
        $election = $this->election();
        $round = $election?->currentRound();

        if ($election === null || $round === null) {
            return [];
        }

        $publication = app(ResultPublication::class);
        $calculator = app(ResultsCalculator::class);

        return app(ResultSlots::class)->slots($election)->map(fn (array $slot): array => [
            'key' => $slot['key'],
            'title' => $slot['ballot']->title.($slot['unit'] !== null ? ' — '.$slot['unit']->name : ''),
            'round' => ($slotRound = app(RoundResolver::class)->roundFor($election, $slot['ballot'], $slot['unit']?->id) ?? $round)->number,
            'tally' => $calculator->tally($slot['ballot'], $slotRound, $slot['unit']),
            'outcome' => $publication->outcome($slot['ballot'], $slot['unit']),
        ])->all();
    }

    /**
     * @return array<int, array{scope: ?Unit, label: string, current: ?OfficialReport, history: Collection<int, OfficialReport>}>
     */
    public function reportRows(): array
    {
        $election = $this->election();

        if ($election === null) {
            return [];
        }

        $reports = app(OfficialReportService::class);

        return app(ResultSlots::class)->reportScopes($election)->map(fn (?Unit $scope): array => [
            'scope' => $scope,
            'label' => $scope?->name ?? 'Keseluruhan',
            'current' => $reports->current($election, $scope),
            'history' => OfficialReport::query()->where('election_id', $election->id)->where('unit_id', $scope?->id)->orderByDesc('version')->get(),
        ])->all();
    }

    /**
     * @return array<int, string>
     */
    public function publishProblems(): array
    {
        $election = $this->election();

        return $election === null ? [] : app(ResultPublication::class)->publishProblems($election);
    }

    public function startVerificationAction(): Action
    {
        return Action::make('startVerification')
            ->label('Mulai Verifikasi')
            ->color('primary')
            ->visible(fn (): bool => $this->election()?->status === ElectionStatus::Ditutup)
            ->requiresConfirmation()
            ->modalDescription('Penetapan hasil dan pengesahan berita acara dilakukan pada status Verifikasi.')
            ->action(fn () => $this->guard(fn () => app(ElectionLifecycle::class)->startVerification($this->authorizedElection(), auth()->user()), 'Status: Verifikasi.'));
    }

    public function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Publikasikan Hasil')
            ->icon('heroicon-o-globe-alt')
            ->color('success')
            ->visible(fn (): bool => in_array($this->election()?->status, [ElectionStatus::Verifikasi, ElectionStatus::Unpublished], true))
            ->disabled(fn (): bool => $this->publishProblems() !== [])
            ->modalHeading('Publikasikan hasil resmi?')
            ->modalDescription('Halaman publik akan menampilkan calon yang terpilih/lolos (tanpa jumlah suara).')
            ->schema([Reauthenticate::field()])
            ->action(fn () => $this->guard(fn () => app(ElectionLifecycle::class)->publish($this->authorizedElection(), auth()->user()), 'Hasil dipublikasikan.'));
    }

    public function unpublishAction(): Action
    {
        return Action::make('unpublish')
            ->label('Tarik dari Publik')
            ->icon('heroicon-o-eye-slash')
            ->color('danger')
            ->visible(fn (): bool => $this->election()?->status === ElectionStatus::Published)
            ->modalDescription('Halaman publik akan menampilkan "Hasil sedang ditinjau ulang". Suara tidak berubah.')
            ->schema([
                Textarea::make('note')->label('Alasan')->required()->maxLength(500),
                Reauthenticate::field(),
            ])
            ->action(fn (array $data) => $this->guard(fn () => app(ElectionLifecycle::class)->unpublish($this->authorizedElection(), auth()->user(), $data['note']), 'Hasil ditarik dari publik.'));
    }

    public function reopenVerificationAction(): Action
    {
        return Action::make('reopenVerification')
            ->label('Kembali ke Verifikasi')
            ->color('gray')
            ->visible(fn (): bool => $this->election()?->status === ElectionStatus::Unpublished)
            ->requiresConfirmation()
            ->action(fn () => $this->guard(fn () => app(ElectionLifecycle::class)->reopenVerification($this->authorizedElection(), auth()->user()), 'Status: Verifikasi.'));
    }

    public function retentionDeadline(): ?Carbon
    {
        $election = $this->election();

        return $election === null ? null : app(DataRetention::class)->linkageDeadline($election);
    }

    public function extendRetentionAction(): Action
    {
        return Action::make('extendRetention')
            ->label('Perpanjang masa simpan detail suara')
            ->color('gray')
            ->size('sm')
            ->visible(fn (): bool => $this->retentionDeadline() !== null && $this->election()?->vote_links_destroyed_at === null)
            ->modalDescription('Gunakan bila ada sengketa yang belum selesai. Perpanjangan tercatat di audit log.')
            ->schema([
                Select::make('days')->label('Tambah')->options([7 => '7 hari', 14 => '14 hari', 30 => '30 hari', 60 => '60 hari'])->required(),
                Textarea::make('reason')->label('Alasan')->required()->maxLength(500),
                Reauthenticate::field(),
            ])
            ->action(fn (array $data) => $this->guard(
                fn () => app(DataRetention::class)->extendLinkage($this->authorizedElection(), (int) $data['days'], $data['reason'], auth()->user()),
                'Masa simpan diperpanjang.',
            ));
    }

    public function nextRoundAction(): Action
    {
        return Action::make('nextRound')
            ->label('Buka Putaran Berikutnya')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (): bool => in_array($this->election()?->status, [ElectionStatus::Ditutup, ElectionStatus::Verifikasi], true))
            ->modalHeading('Buka putaran berikutnya')
            ->modalDescription('Untuk seri atau keputusan panitia. Pemilihan kembali Berlangsung hanya untuk surat suara/RT yang dipilih, dengan calon yang dipilih. Mode Resmi: laptop meja dan bilik perlu dipasang ulang dengan token baru.')
            ->schema(fn (): array => [
                CheckboxList::make('slots')
                    ->label('Surat suara / RT yang diulang')
                    ->options(fn (): array => app(ResultSlots::class)->slots($this->election())
                        ->mapWithKeys(fn (array $slot): array => [$slot['key'] => $slot['ballot']->title.($slot['unit'] !== null ? ' — '.$slot['unit']->name : '')])
                        ->all())
                    ->required()
                    ->live(),
                CheckboxList::make('candidates')
                    ->label('Calon yang ikut putaran berikutnya (minimal dua per surat suara/RT)')
                    ->options(function (Get $get): array {
                        $chosen = collect($get('slots') ?? []);
                        $options = [];

                        // Kunci = ID calon (jangan pakai flatMap: kunci numerik akan diurutkan ulang).
                        $election = $this->election();
                        $latestRound = $election?->rounds()->orderByDesc('number')->first();

                        foreach (app(ResultSlots::class)->slots($election)->filter(fn (array $slot): bool => $chosen->contains($slot['key'])) as $slot) {
                            $prefix = $slot['ballot']->title.($slot['unit'] !== null ? ' '.$slot['unit']->name : '').' · ';

                            // Urut peringkat putaran terakhir + jumlah suara, agar calon yang seri terlihat berdampingan.
                            $tally = $latestRound === null ? [] : app(ResultsCalculator::class)->tally($slot['ballot'], $latestRound, $slot['unit'])['candidates'];

                            foreach ($tally as $row) {
                                $options[$row['candidate']->id] = $prefix."#{$row['rank']} · ".$row['candidate']->displayNumber().' '.$row['candidate']->name." ({$row['votes']} suara)";
                            }

                            $candidates = $slot['ballot']->ballotCandidates()
                                ->when($slot['unit'] !== null, fn ($query) => $query->where('unit_id', $slot['unit']->id))
                                ->get();

                            foreach ($candidates as $candidate) {
                                $options[$candidate->id] ??= $prefix.$candidate->displayNumber().' '.$candidate->name;
                            }
                        }

                        return $options;
                    })
                    ->required(),
                Textarea::make('reason')->label('Alasan (mis. hasil seri, keputusan rapat panitia)')->required()->maxLength(500),
                Reauthenticate::field(),
            ])
            ->action(fn (array $data) => $this->guard(
                fn () => app(NextRoundService::class)->open($this->authorizedElection(), $data['slots'], array_map('intval', $data['candidates']), $data['reason'], auth()->user()),
                'Putaran berikutnya dibuka. Pemilihan kembali Berlangsung.',
            ));
    }

    public function decideAction(): Action
    {
        return Action::make('decide')
            ->label('Tetapkan')
            ->icon('heroicon-o-check-badge')
            ->visible(fn (): bool => in_array($this->election()?->status, [ElectionStatus::Verifikasi, ElectionStatus::Unpublished], true))
            ->modalHeading(fn (array $arguments): string => 'Penetapan: '.($this->slotFromArguments($arguments)['title'] ?? ''))
            ->modalDescription('Sistem tidak menentukan pemenang. Pilih calon yang ditetapkan panitia. Satu calon = "Terpilih", beberapa calon = "Lolos".')
            ->schema(fn (array $arguments): array => [
                Select::make('status')->label('Status')->options(OutcomeStatus::class)->default(OutcomeStatus::Ditetapkan)->required()->live(),
                CheckboxList::make('candidates')
                    ->label('Calon yang ditetapkan')
                    ->options(fn (): array => $this->candidateOptions($arguments))
                    ->visible(fn (Get $get): bool => in_array($get('status'), [OutcomeStatus::Ditetapkan, OutcomeStatus::Ditetapkan->value], true)),
                Textarea::make('note')->label('Catatan (wajib jika belum ditetapkan)')->maxLength(500),
            ])
            ->action(function (array $data, array $arguments): void {
                $slot = $this->slotFromArguments($arguments);
                abort_if($slot === null, 404);

                $status = $data['status'] instanceof OutcomeStatus ? $data['status'] : OutcomeStatus::from($data['status']);

                $this->guard(fn () => app(ResultPublication::class)->decide(
                    $this->authorizedElection(),
                    $slot['ballot'],
                    $slot['unit'],
                    $status,
                    $data['candidates'] ?? [],
                    $data['note'] ?? null,
                    auth()->user(),
                ), 'Penetapan disimpan. Berita acara lingkup ini perlu dibuat ulang.');
            });
    }

    public function draftReportAction(): Action
    {
        return Action::make('draftReport')
            ->label(fn (array $arguments): string => ($arguments['revise'] ?? false) ? 'Buat versi baru' : 'Buat draf')
            ->icon('heroicon-o-document-plus')
            ->color('gray')
            ->schema(fn (array $arguments): array => ($arguments['revise'] ?? false)
                ? [Textarea::make('reason')->label('Alasan perubahan')->required()->maxLength(500)]
                : [])
            ->requiresConfirmation(fn (array $arguments): bool => ! ($arguments['revise'] ?? false))
            ->action(fn (array $data, array $arguments) => $this->guard(
                fn () => app(OfficialReportService::class)->createDraft($this->authorizedElection(), $this->scopeFromArguments($arguments), auth()->user(), $data['reason'] ?? null),
                'Draf berita acara dibuat. Cetak, tanda tangani, lalu sahkan.',
            ));
    }

    public function ratifyReportAction(): Action
    {
        return Action::make('ratifyReport')
            ->label('Sahkan')
            ->icon('heroicon-o-lock-closed')
            ->color('success')
            ->modalHeading('Sahkan berita acara?')
            ->modalDescription('Setelah disahkan, isi berita acara terkunci. Perubahan hanya lewat versi baru dengan alasan.')
            ->schema([Reauthenticate::field()])
            ->action(function (array $arguments): void {
                $report = OfficialReport::query()
                    ->where('election_id', $this->authorizedElection()->id)
                    ->where('public_id', $arguments['report'] ?? '')
                    ->firstOrFail();

                $this->guard(fn () => app(OfficialReportService::class)->ratify($report, auth()->user()), 'Berita acara disahkan.');
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, string>
     */
    private function candidateOptions(array $arguments): array
    {
        $slot = $this->slotFromArguments($arguments);

        if ($slot === null) {
            return [];
        }

        $candidates = $slot['ballot']->ballotCandidates()
            ->when($slot['unit'] !== null, fn ($query) => $query->where('unit_id', $slot['unit']->id))
            ->get();

        // Peringkat + suara per putaran agar panitia tidak perlu mencocokkan dengan tabel; urut putaran terakhir dulu.
        $rounds = $this->election()?->rounds()->orderBy('number')->get() ?? collect();
        $calculator = app(ResultsCalculator::class);
        $results = [];
        $order = [];

        foreach ($rounds as $round) {
            foreach ($calculator->tally($slot['ballot'], $round, $slot['unit'])['candidates'] as $row) {
                $results[$row['candidate']->id][] = ($rounds->count() > 1 ? "P{$round->number} " : '')."#{$row['rank']} ({$row['votes']} suara)";
                $order[$row['candidate']->id] = $round->number * -1000 + $row['rank'];
            }
        }

        return $candidates
            ->sortBy(fn ($candidate): int => $order[$candidate->id] ?? PHP_INT_MAX)
            ->mapWithKeys(fn ($candidate): array => [
                $candidate->id => $candidate->displayNumber().' · '.$candidate->name
                    .(isset($results[$candidate->id]) ? ' — '.implode(' · ', $results[$candidate->id]) : ''),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{key: string, ballot: Ballot, unit: ?Unit, title: string}|null
     */
    private function slotFromArguments(array $arguments): ?array
    {
        $election = $this->election();

        if ($election === null) {
            return null;
        }

        $slot = app(ResultSlots::class)->slots($election)->firstWhere('key', $arguments['slot'] ?? '');

        return $slot === null ? null : $slot + ['title' => $slot['ballot']->title.($slot['unit'] !== null ? ' — '.$slot['unit']->name : '')];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function scopeFromArguments(array $arguments): ?Unit
    {
        $unitId = (int) ($arguments['unit'] ?? 0);

        if ($unitId === 0) {
            return null;
        }

        return app(ResultSlots::class)->reportScopes($this->authorizedElection())->first(fn (?Unit $unit): bool => $unit?->id === $unitId)
            ?? abort(404);
    }

    private function authorizedElection(): Election
    {
        $election = $this->election();
        abort_unless($election !== null && auth()->user()?->isSuperAdmin(), 403);

        return $election;
    }

    private function guard(callable $callback, string $success): void
    {
        try {
            $callback();
        } catch (VotingException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
    }

    public function reportStatusColor(?ReportStatus $status): string
    {
        return $status?->getColor() ?? 'gray';
    }
}
