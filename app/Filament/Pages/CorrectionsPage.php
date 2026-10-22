<?php

namespace App\Filament\Pages;

use App\Enums\CorrectionReason;
use App\Enums\CorrectionStatus;
use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Filament\Support\Reauthenticate;
use App\Filament\Support\Workspace;
use App\Models\Attendee;
use App\Models\Ballot;
use App\Models\Election;
use App\Models\User;
use App\Models\VoteCorrection;
use App\Models\Voter;
use App\Services\Corrections\CorrectionService;
use App\Services\Voting\VotingException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Koreksi Suara (Mode Resmi): Admin RT mengajukan, Super Admin memutuskan. Pilihan kandidat tidak ditampilkan.
 */
class CorrectionsPage extends Page
{
    protected string $view = 'filament.pages.corrections-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Hari H';

    protected static ?string $navigationLabel = 'Koreksi Suara';

    protected static ?string $title = 'Koreksi Suara';

    protected static ?string $slug = 'koreksi-suara';

    protected static ?int $navigationSort = 4;

    /**
     * Petugas Meja Izin mengajukan koreksi langsung dari baris pemilih di Meja Izin, jadi menu ini tidak ditampilkan
     * untuk mereka (halamannya tetap bisa dibuka). Super Admin memutuskan di sini.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return Workspace::shows(ElectionMode::Resmi) && ! DeskPage::canAccess();
    }

    public string $search = '';

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
            ->whereIn('status', [ElectionStatus::Berlangsung, ElectionStatus::Paused, ElectionStatus::Ditutup, ElectionStatus::Verifikasi, ElectionStatus::Unpublished])
            ->latest()
            ->first();
    }

    /**
     * Pemilih yang punya suara sah (untuk diajukan koreksinya). Admin RT: RT sendiri.
     *
     * @return Collection<int, array{voter: Voter, ballots: Collection<int, Ballot>}>
     */
    public function candidatesForCorrection(): Collection
    {
        $election = $this->election();

        if ($election === null || mb_strlen(trim($this->search)) < 2) {
            return collect();
        }

        $voters = Voter::query()
            ->with('unit')
            ->when(! $this->user()->isSuperAdmin(), fn ($query) => $query->where('unit_id', $this->user()->unit_id))
            ->where('name_search', 'like', '%'.addcslashes(Attendee::normalizeForSearch($this->search), '%_\\').'%')
            ->whereHas('votes', fn ($query) => $query->where('election_id', $election->id)->where('status', 'SAH'))
            ->limit(15)
            ->get();

        return $voters->map(fn (Voter $voter): array => [
            'voter' => $voter,
            'ballots' => $election->ballots()->whereIn('id', $voter->votes()->where('election_id', $election->id)->where('status', 'SAH')->pluck('ballot_id'))->get(),
        ]);
    }

    /**
     * @return Collection<int, VoteCorrection>
     */
    public function corrections(): Collection
    {
        $election = $this->election();

        if ($election === null) {
            return collect();
        }

        return VoteCorrection::query()
            ->where('election_id', $election->id)
            ->when(! $this->user()->isSuperAdmin(), fn ($query) => $query->where('unit_id', $this->user()->unit_id))
            ->with(['voter', 'ballot', 'unit', 'requester', 'decider'])
            ->latest()
            ->get();
    }

    public function requestAction(): Action
    {
        return Action::make('request')
            // Super Admin: sekali klik langsung dibatalkan (mengajukan sekaligus memutuskan). Admin RT tanpa meja: mengajukan.
            ->label(fn (): string => $this->user()->isSuperAdmin() ? 'Batalkan suara' : 'Ajukan pembatalan')
            ->color('warning')
            ->size('sm')
            ->modalHeading(fn (): string => $this->user()->isSuperAdmin() ? 'Batalkan suara ini?' : 'Ajukan pembatalan suara')
            ->modalDescription(fn (): string => $this->user()->isSuperAdmin()
                ? 'Suara tidak dihapus, hanya berstatus dibatalkan dan tercatat di berita acara. Selama pemilihan berlangsung, pemilih bisa diizinkan memilih ulang.'
                : 'Suara tidak dihapus, hanya berstatus dibatalkan setelah disetujui Super Admin. Selama pemilihan berlangsung, pemilih bisa diizinkan memilih ulang.')
            ->modalSubmitActionLabel(fn (): string => $this->user()->isSuperAdmin() ? 'Ya, batalkan' : 'Ajukan')
            ->schema(fn (): array => array_values(array_filter([
                Select::make('reason')->label('Alasan')->options(CorrectionReason::class)->required(),
                Textarea::make('note')->label('Catatan (wajib bila alasan "Lainnya")')->maxLength(500),
                $this->user()->isSuperAdmin() ? Reauthenticate::field() : null,
            ])))
            ->action(function (array $data, array $arguments): void {
                $election = $this->election();
                abort_if($election === null, 404);

                $voter = Voter::query()
                    ->when(! $this->user()->isSuperAdmin(), fn ($query) => $query->where('unit_id', $this->user()->unit_id))
                    ->where('public_id', $arguments['voter'] ?? '')
                    ->firstOrFail();
                $ballot = $election->ballots()->where('public_id', $arguments['ballot'] ?? '')->firstOrFail();
                $reason = $data['reason'] instanceof CorrectionReason ? $data['reason'] : CorrectionReason::from($data['reason']);

                if ($this->user()->isSuperAdmin()) {
                    $this->run(function () use ($election, $voter, $ballot, $reason, $data): void {
                        $service = app(CorrectionService::class);
                        $correction = $service->request($election, $voter, $ballot, $reason, $data['note'] ?? null, $this->user(), notify: false);
                        $service->approve($correction, $this->user(), 'Diputuskan langsung oleh Super Admin');
                    }, 'Suara dibatalkan.');

                    return;
                }

                $this->run(fn () => app(CorrectionService::class)->request($election, $voter, $ballot, $reason, $data['note'] ?? null, $this->user()), 'Pengajuan dikirim ke Super Admin.');
            });
    }

    public function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Setujui')
            ->color('danger')
            ->size('sm')
            ->visible(fn (): bool => $this->user()->isSuperAdmin())
            ->modalHeading('Setujui pembatalan suara?')
            ->schema([
                Textarea::make('note')->label('Catatan keputusan (opsional)')->maxLength(500),
                Reauthenticate::field(),
            ])
            ->action(fn (array $data, array $arguments) => $this->run(
                fn () => app(CorrectionService::class)->approve($this->correctionFromArguments($arguments), $this->user(), $data['note'] ?? null),
                'Suara dibatalkan.',
            ));
    }

    public function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Tolak')
            ->color('gray')
            ->size('sm')
            ->visible(fn (): bool => $this->user()->isSuperAdmin())
            ->schema([Textarea::make('note')->label('Alasan penolakan')->required()->maxLength(500)])
            ->action(fn (array $data, array $arguments) => $this->run(
                fn () => app(CorrectionService::class)->reject($this->correctionFromArguments($arguments), $this->user(), $data['note']),
                'Pengajuan ditolak.',
            ));
    }

    public function withdrawAction(): Action
    {
        return Action::make('withdraw')
            ->label('Tarik')
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->action(fn (array $arguments) => $this->run(
                fn () => app(CorrectionService::class)->withdraw($this->correctionFromArguments($arguments), $this->user()),
                'Pengajuan ditarik.',
            ));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function correctionFromArguments(array $arguments): VoteCorrection
    {
        return $this->corrections()->firstWhere('public_id', $arguments['correction'] ?? '') ?? abort(404);
    }

    private function run(callable $callback, string $success): void
    {
        try {
            $callback();
        } catch (VotingException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
    }

    public function isPending(VoteCorrection $correction): bool
    {
        return $correction->status === CorrectionStatus::Diajukan;
    }
}
