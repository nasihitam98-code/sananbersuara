<?php

namespace App\Filament\Pages;

use App\Enums\DeviceKind;
use App\Enums\DeviceReleaseReason;
use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\PermitCancelReason;
use App\Enums\TpsPauseReason;
use App\Enums\VoteStatus;
use App\Filament\Support\Workspace;
use App\Models\Attendee;
use App\Models\BallotVoter;
use App\Models\Device;
use App\Models\Election;
use App\Models\Permit;
use App\Models\TpsPause;
use App\Models\User;
use App\Models\Vote;
use App\Models\Voter;
use App\Services\Devices\DeviceManager;
use App\Services\Permits\PermitManager;
use App\Services\Permits\TpsPauseService;
use App\Services\Voting\RoundResolver;
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
 * Meja Izin (Mode Resmi, bagian 5.5 dan 11): hanya Admin RT berizin Petugas Meja,
 * dan tombol aksi hanya berlaku dari laptop Meja terdaftar RT yang sama (dicek di server).
 */
class DeskPage extends Page
{
    protected string $view = 'filament.pages.desk-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Hari H';

    protected static ?string $navigationLabel = 'Meja Izin';

    protected static ?string $title = 'Meja Izin';

    protected static ?string $slug = 'meja-izin';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        return Workspace::shows(ElectionMode::Resmi);
    }

    public string $search = '';

    /**
     * Penugasan terakhir: "Silakan ke Bilik N".
     *
     * @var array{name: string, booth: string}|null
     */
    public ?array $lastAssignment = null;

    /**
     * Token bilik yang baru dibuat (tampil sekali).
     *
     * @var array{booth: string, token: string}|null
     */
    public ?array $issuedToken = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isAdminRt() && $user->hasPermissionTo(User::PERMISSION_DESK);
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
            ->whereIn('status', [ElectionStatus::Ready, ElectionStatus::Berlangsung, ElectionStatus::Paused])
            ->latest()
            ->first();
    }

    /**
     * Laptop Meja terdaftar untuk RT petugas ini, atau null jika laptop ini bukan meja RT ini.
     */
    public function desk(): ?Device
    {
        $election = $this->election();
        $desk = app(DeviceManager::class)->authenticate(request(), DeviceKind::Meja);

        if ($election === null || $desk === null || $desk->election_id !== $election->id || $desk->unit_id !== $this->user()->unit_id) {
            return null;
        }

        app(DeviceManager::class)->heartbeat($desk, request());

        return $desk;
    }

    /**
     * @return Collection<int, Device>
     */
    public function booths(): Collection
    {
        $election = $this->election();

        if ($election === null) {
            return collect();
        }

        return Device::query()
            ->where('election_id', $election->id)
            ->where('unit_id', $this->user()->unit_id)
            ->where('kind', DeviceKind::Bilik)
            ->with(['unit', 'activePermit.voter'])
            ->orderBy('number')
            ->get();
    }

    /**
     * @return array<int, array{voter: Voter, status: array{ballots: array<int, string>, permit: ?Permit}}>
     */
    public function results(): array
    {
        $election = $this->election();

        if ($election === null || mb_strlen(trim($this->search)) < 2) {
            return [];
        }

        $voters = Voter::query()
            ->where('unit_id', $this->user()->unit_id)
            ->where('name_search', 'like', '%'.addcslashes(Attendee::normalizeForSearch($this->search), '%_\\').'%')
            ->orderBy('name')
            ->limit(20)
            ->get();

        $statuses = app(PermitManager::class)->statuses($voters, $election);

        return $voters->map(fn (Voter $voter): array => ['voter' => $voter, 'status' => $statuses[$voter->id]])->all();
    }

    /**
     * Partisipasi RT sendiri per surat suara (tanpa angka per calon).
     *
     * @return array<int, array{title: string, eligible: int, voted: int, percent: float}>
     */
    public function participation(): array
    {
        $election = $this->election();
        $round = $election?->currentRound();

        if ($election === null) {
            return [];
        }

        return $election->ballots()->get()->map(function ($ballot) use ($round): array {
            $eligibleIds = BallotVoter::query()->where('ballot_id', $ballot->id)->where('unit_id', $this->user()->unit_id)->whereNull('revoked_at')->pluck('voter_id');
            $ballotRound = app(RoundResolver::class)->roundFor($ballot->election, $ballot, $this->user()->unit_id) ?? $round;
            $voted = $ballotRound === null ? 0 : Vote::query()->where('ballot_id', $ballot->id)->where('round_id', $ballotRound->id)->where('status', VoteStatus::Sah)->whereIn('voter_id', $eligibleIds)->count();
            $eligible = $eligibleIds->count();

            return ['title' => $ballot->title, 'eligible' => $eligible, 'voted' => $voted, 'percent' => $eligible === 0 ? 0.0 : round($voted * 100 / $eligible, 1)];
        })->filter(fn (array $row): bool => $row['eligible'] > 0)->values()->all();
    }

    public function grantAction(): Action
    {
        return Action::make('grant')
            ->visible(fn (): bool => $this->desk() !== null)
            ->label('Izinkan Memilih')
            ->color('success')
            ->size('lg')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Izinkan '.($this->voterFromArguments($arguments)?->name ?? '').' memilih?')
            ->modalDescription(fn (array $arguments): string => 'Pastikan orangnya sesuai (wajah/KTP). Alamat: '.($this->voterFromArguments($arguments)?->address ?? '-'))
            ->modalSubmitActionLabel('Ya, izinkan')
            ->action(function (array $arguments): void {
                $voter = $this->voterFromArguments($arguments);
                $desk = $this->requireDesk();
                abort_if($voter === null, 404);

                try {
                    $permit = app(PermitManager::class)->grant($voter, $this->user(), $desk);
                } catch (VotingException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                $this->lastAssignment = ['name' => $voter->name, 'booth' => $permit->device->name()];
            });
    }

    public function cancelPermitAction(): Action
    {
        return Action::make('cancelPermit')
            ->visible(fn (): bool => $this->desk() !== null)
            ->label('Batalkan Izin')
            ->color('danger')
            ->size('sm')
            ->schema([Select::make('reason')->label('Alasan')->options(PermitCancelReason::class)->required()])
            ->action(function (array $data, array $arguments): void {
                $permit = Permit::query()->where('public_id', $arguments['permit'] ?? '')->where('unit_id', $this->user()->unit_id)->firstOrFail();
                $reason = $data['reason'] instanceof PermitCancelReason ? $data['reason'] : PermitCancelReason::from($data['reason']);

                $this->run(fn () => app(PermitManager::class)->cancel($permit, $reason, $this->user(), $this->requireDesk()), 'Izin dibatalkan. Bilik terkunci kembali.');
            });
    }

    public function issueBoothTokenAction(): Action
    {
        return Action::make('issueBoothToken')
            ->visible(fn (): bool => $this->desk() !== null)
            ->label('Buat Token')
            ->size('sm')
            ->requiresConfirmation(fn (array $arguments): bool => $this->boothFromArguments($arguments)?->isPaired() === true)
            ->modalDescription('Bilik ini sedang terpasang. Token baru dipakai untuk mengganti laptop; laptop lama akan terlepas saat token dipakai.')
            ->action(function (array $arguments): void {
                $booth = $this->boothFromArguments($arguments);
                $desk = $this->requireDesk();
                abort_if($booth === null, 404);
                app(PermitManager::class)->assertDesk($desk, $this->user(), $booth->unit_id);

                $this->run(function () use ($booth): void {
                    $this->issuedToken = ['booth' => $booth->name(), 'token' => app(DeviceManager::class)->issueToken($booth, $this->user())];
                }, 'Token dibuat. Ketik di laptop bilik pada alamat /bilik.');
            });
    }

    public function releaseBoothAction(): Action
    {
        return Action::make('releaseBooth')
            ->visible(fn (): bool => $this->desk() !== null)
            ->label('Lepas')
            ->color('danger')
            ->size('sm')
            ->modalDescription(fn (array $arguments): string => $this->boothFromArguments($arguments)?->activePermit !== null
                ? 'PERHATIAN: ada pemilih di bilik ini. Surat suara yang belum dikonfirmasi tidak tersimpan.'
                : 'Laptop ini langsung tidak bisa dipakai lagi.')
            ->schema([Select::make('reason')->label('Alasan')->options(DeviceReleaseReason::class)->required()])
            ->action(function (array $data, array $arguments): void {
                $booth = $this->boothFromArguments($arguments);
                $desk = $this->requireDesk();
                abort_if($booth === null, 404);
                app(PermitManager::class)->assertDesk($desk, $this->user(), $booth->unit_id);
                $reason = $data['reason'] instanceof DeviceReleaseReason ? $data['reason'] : DeviceReleaseReason::from($data['reason']);

                $this->run(fn () => app(DeviceManager::class)->release($booth, $reason, $this->user()), 'Bilik dilepas.');
            });
    }

    public function tpsPause(): ?TpsPause
    {
        $election = $this->election();

        return $election === null ? null : app(TpsPauseService::class)->active($election, (int) $this->user()->unit_id);
    }

    public function pauseTpsAction(): Action
    {
        return Action::make('pauseTps')
            ->label('Jeda TPS')
            ->icon('heroicon-o-pause')
            ->color('warning')
            ->visible(fn (): bool => $this->desk() !== null && $this->election()?->status === ElectionStatus::Berlangsung && $this->tpsPause() === null)
            ->modalDescription('Izin baru di RT ini ditolak sampai TPS dilanjutkan. Pemilih yang sudah di bilik tetap bisa menyelesaikan.')
            ->schema([
                Select::make('reason')->label('Alasan')->options(TpsPauseReason::class)->required(),
                Textarea::make('note')->label('Catatan')->maxLength(500),
            ])
            ->action(function (array $data): void {
                $desk = $this->requireDesk();
                $reason = $data['reason'] instanceof TpsPauseReason ? $data['reason'] : TpsPauseReason::from($data['reason']);

                $this->run(fn () => app(TpsPauseService::class)->pause($this->election(), $desk->unit, $reason, $data['note'] ?? null, $this->user()), 'TPS dijeda.');
            });
    }

    public function resumeTpsAction(): Action
    {
        return Action::make('resumeTps')
            ->label('Lanjutkan TPS')
            ->icon('heroicon-o-play')
            ->color('success')
            ->visible(fn (): bool => $this->desk() !== null && $this->tpsPause() !== null)
            ->requiresConfirmation()
            ->action(function (): void {
                $desk = $this->requireDesk();

                $this->run(fn () => app(TpsPauseService::class)->resume($this->election(), $desk->unit, $this->user()), 'TPS dilanjutkan.');
            });
    }

    public function dismissAssignment(): void
    {
        $this->lastAssignment = null;
    }

    public function dismissToken(): void
    {
        $this->issuedToken = null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function voterFromArguments(array $arguments): ?Voter
    {
        return Voter::query()->where('unit_id', $this->user()->unit_id)->where('public_id', $arguments['voter'] ?? '')->first();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function boothFromArguments(array $arguments): ?Device
    {
        return $this->booths()->firstWhere('public_id', $arguments['booth'] ?? '');
    }

    private function requireDesk(): Device
    {
        $desk = $this->desk();

        if ($desk === null) {
            Notification::make()->title('Laptop ini bukan laptop Meja terdaftar untuk RT Anda.')->danger()->send();
            abort(403);
        }

        return $desk;
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
}
