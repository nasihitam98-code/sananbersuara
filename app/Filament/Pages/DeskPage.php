<?php

namespace App\Filament\Pages;

use App\Enums\CorrectionReason;
use App\Enums\CorrectionStatus;
use App\Enums\DeviceKind;
use App\Enums\DeviceReleaseReason;
use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\EmergencyAddReason;
use App\Enums\PermitCancelReason;
use App\Enums\PermitStatus;
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
use App\Models\VoteCorrection;
use App\Models\Voter;
use App\Services\Corrections\CorrectionService;
use App\Services\Devices\DeviceManager;
use App\Services\Permits\PermitManager;
use App\Services\Permits\TpsPauseService;
use App\Services\Voters\DuplicateNameWarning;
use App\Services\Voters\VoterRegistry;
use App\Services\Voting\RoundResolver;
use App\Services\Voting\VotingException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

    /** Tab daftar pemilih: belum (termasuk yang sedang di bilik), sudah, semua. */
    public string $voterTab = 'belum';

    /** @var array<int, array<string, mixed>>|null dihitung sekali per render */
    private ?array $voterRowsCache = null;

    /**
     * Penugasan terakhir: "Silakan ke Bilik N".
     *
     * @var array{name: string, booth: string, permit?: int}|null
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
     * Pemilih RT pada tab yang dipilih, disaring nama bila kotak cari diisi.
     *
     * @return array<int, array{voter: Voter, status: array{ballots: array<int, string>, permit: ?Permit}, state: string}>
     */
    public function results(): array
    {
        $tab = in_array($this->voterTab, ['belum', 'sudah', 'semua'], true) ? $this->voterTab : 'belum';

        return collect($this->voterRows())
            ->filter(fn (array $row): bool => match ($tab) {
                'belum' => in_array($row['state'], ['belum', 'di_bilik'], true),
                'sudah' => $row['state'] === 'sudah',
                default => true,
            })
            // Saring nama, atau No. urut RT bila yang diketik angka (mis. "75").
            ->when(filled(trim($this->search)), fn ($rows) => $rows->filter(
                fn (array $row): bool => ctype_digit(trim($this->search))
                    ? $row['voter']->rt_number === (int) trim($this->search)
                    : str_contains($row['voter']->name_search, Attendee::normalizeForSearch($this->search)),
            ))
            ->values()
            ->all();
    }

    /**
     * Jumlah per tab (tanpa saringan nama).
     *
     * @return array{belum: int, sudah: int, semua: int}
     */
    public function voterCounts(): array
    {
        $rows = collect($this->voterRows());

        return [
            'belum' => $rows->whereIn('state', ['belum', 'di_bilik'])->count(),
            'sudah' => $rows->where('state', 'sudah')->count(),
            'semua' => $rows->count(),
        ];
    }

    /**
     * Semua pemilih RT sendiri dengan statusnya, langsung tampil tanpa harus mengetik (permintaan pemilik 2026-10-23).
     * Sebelum dimulai daftar beku belum ada, jadi semua dianggap "belum".
     *
     * @return array<int, array{voter: Voter, status: array<string, mixed>, state: string, correction_pending: bool}>
     */
    private function voterRows(): array
    {
        if ($this->voterRowsCache !== null) {
            return $this->voterRowsCache;
        }

        $election = $this->election();

        if ($election === null) {
            return $this->voterRowsCache = [];
        }

        $voters = Voter::query()->where('unit_id', $this->user()->unit_id)->orderBy('rt_number')->orderBy('name')->get();
        $statuses = app(PermitManager::class)->statuses($voters, $election);
        $started = $election->status !== ElectionStatus::Ready;
        $pendingCorrections = VoteCorrection::query()
            ->where('election_id', $election->id)
            ->where('status', CorrectionStatus::Diajukan)
            ->whereIn('voter_id', $voters->modelKeys())
            ->pluck('voter_id')
            ->flip();

        return $this->voterRowsCache = $voters->map(function (Voter $voter) use ($statuses, $started, $pendingCorrections): array {
            $status = $statuses[$voter->id];
            $ballots = collect($status['ballots']);

            $state = match (true) {
                $status['permit'] !== null => 'di_bilik',
                ! $started => $voter->is_active ? 'belum' : 'tidak_berhak',
                $ballots->every(fn (string $value): bool => $value === 'TIDAK_BERHAK') => 'tidak_berhak',
                $ballots->contains('BELUM') => 'belum',
                default => 'sudah',
            };

            return ['voter' => $voter, 'status' => $status, 'state' => $state, 'correction_pending' => $pendingCorrections->has($voter->id)];
        })->all();
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
            ->modalDescription(fn (array $arguments): string => 'Pastikan orangnya benar.'.(filled($this->voterFromArguments($arguments)?->address) ? ' Alamat: '.$this->voterFromArguments($arguments)->address.'.' : ''))
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

                $this->lastAssignment = ['name' => $voter->name, 'booth' => $permit->device->name(), 'permit' => $permit->id];
            });
    }

    /**
     * Warga datang tapi belum terdaftar: tambah darurat langsung dari meja (tanpa pindah ke Data Pemilih). Dicatat
     * khusus di laporan dan dilaporkan ke Super Admin; hanya saat voting berjalan dan bagi yang boleh mengelola pemilih.
     */
    public function emergencyAddAction(): Action
    {
        return Action::make('emergencyAdd')
            ->label('+ Tambah pemilih darurat')
            ->color('gray')
            ->visible(fn (): bool => in_array($this->election()?->status, [ElectionStatus::Berlangsung, ElectionStatus::Paused], true)
                && $this->user()->can('create', Voter::class))
            ->modalHeading(fn (): string => 'Tambah pemilih darurat '.$this->user()->unit?->name)
            ->modalDescription('Untuk warga yang berhak tapi belum ada di daftar. Dicatat khusus di laporan dan dilaporkan ke Super Admin. Setelah ditambah, namanya langsung muncul di daftar dan bisa diizinkan memilih.')
            ->modalSubmitActionLabel('Tambahkan')
            ->schema([
                TextInput::make('name')->label('Nama lengkap')->required()->minLength(3)->maxLength(120),
                Select::make('reason')->label('Alasan')->options(EmergencyAddReason::class)->required(),
                Checkbox::make('confirm_different_person')->label('Orang berbeda (jika muncul peringatan nama sama)'),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    $voter = app(VoterRegistry::class)->create(
                        ['name' => $data['name']],
                        (int) $this->user()->unit_id,
                        $this->user(),
                        null,
                        (bool) ($data['confirm_different_person'] ?? false),
                        $data['reason'] instanceof EmergencyAddReason ? $data['reason']->value : (string) $data['reason'],
                    );
                } catch (DuplicateNameWarning $warning) {
                    Notification::make()->title('Nama yang sama sudah terdaftar')->body(implode("\n", $warning->similar)."\n\nBila orang berbeda, centang kotaknya lalu Tambahkan lagi.")->warning()->persistent()->send();
                    $action->halt();

                    return;
                } catch (VotingException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                $this->search = $voter->name;
                $this->voterTab = 'belum';
                Notification::make()->title("{$voter->name} ditambahkan. Silakan izinkan memilih.")->success()->send();
            });
    }

    /**
     * Koreksi suara langsung dari baris pemilih: diputuskan di RT tanpa menunggu Super Admin (keputusan pemilik
     * 2026-10-23). Suara berstatus dibatalkan dan pemilih kembali ke "Belum memilih"; Super Admin diberi tahu.
     */
    public function requestCorrectionAction(): Action
    {
        return Action::make('requestCorrection')
            ->label('Koreksi')
            ->color('gray')
            ->size('sm')
            ->visible(fn (): bool => in_array($this->election()?->status, [ElectionStatus::Berlangsung, ElectionStatus::Paused], true))
            ->modalHeading(fn (array $arguments): string => 'Koreksi suara '.($this->voterFromArguments($arguments)?->name ?? ''))
            ->modalDescription('Untuk suara bermasalah, mis. izin salah orang atau pemilih dipaksa. Suara surat suara yang dipilih langsung dibatalkan (tidak dihapus), orangnya kembali ke "Belum memilih" dan bisa diizinkan memilih ulang. Tercatat di berita acara; Super Admin diberi tahu. Pilihan calon tidak pernah ditampilkan.')
            ->modalSubmitActionLabel('Batalkan suara ini')
            ->schema(fn (array $arguments): array => [
                // Bawaan semua surat suara yang sudah dipilih: biasanya orangnya mengulang semuanya (RT dan RW).
                CheckboxList::make('ballots')
                    ->label('Surat suara yang dibatalkan')
                    ->options(fn (): array => $this->votedBallotOptions($arguments))
                    ->default(fn (): array => array_keys($this->votedBallotOptions($arguments)))
                    ->helperText('Lepas centang bila hanya satu surat suara yang bermasalah.')
                    ->required()
                    ->validationMessages(['required' => 'Centang minimal satu surat suara.']),
                Select::make('reason')->label('Alasan')->options(CorrectionReason::class)->required(),
                Textarea::make('note')->label('Catatan (wajib bila alasan "Lainnya")')->maxLength(500),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $election = $this->election();
                $voter = $this->voterFromArguments($arguments);
                $ballots = $election?->ballots()->whereIn('id', (array) ($data['ballots'] ?? []))->orderBy('sort')->get() ?? collect();

                if ($election === null || $voter === null || $ballots->isEmpty()) {
                    return;
                }

                $reason = $data['reason'] instanceof CorrectionReason ? $data['reason'] : CorrectionReason::from((string) $data['reason']);
                $cancelled = [];

                foreach ($ballots as $ballot) {
                    try {
                        app(CorrectionService::class)->correctAtDesk($election, $voter, $ballot, $reason, $data['note'] ?? null, $this->user());
                        $cancelled[] = $ballot->title;
                    } catch (VotingException $exception) {
                        Notification::make()->title("{$ballot->title}: {$exception->getMessage()}")->danger()->send();
                    }
                }

                if ($cancelled === []) {
                    $action->halt();

                    return;
                }

                Notification::make()->title('Suara '.implode(' dan ', $cancelled)." {$voter->name} dibatalkan. Silakan izinkan memilih ulang.")->success()->send();
            });
    }

    /**
     * Surat suara yang sudah dipilih orang ini (yang bisa dikoreksi).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<int, string>
     */
    private function votedBallotOptions(array $arguments): array
    {
        $election = $this->election();
        $voter = $this->voterFromArguments($arguments);

        if ($election === null || $voter === null) {
            return [];
        }

        $status = app(PermitManager::class)->statuses($voter->newCollection([$voter]), $election)[$voter->id];

        return $election->ballots()->get()
            ->filter(fn ($ballot): bool => ($status['ballots'][$ballot->id] ?? null) === 'SUDAH')
            ->mapWithKeys(fn ($ballot): array => [$ballot->id => $ballot->title])
            ->all();
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
            ->requiresConfirmation()
            // Konfirmasi hanya bila bilik sedang terpasang (deskripsi saja membuat Filament selalu membuka jendela).
            ->modalHidden(fn (array $arguments): bool => $this->boothFromArguments($arguments)?->isPaired() !== true)
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

    /**
     * Jumlah bilik diatur tiap RT sendiri (keputusan pemilik 2026-10-25): tambah satu bilik lagi.
     */
    public function addBoothAction(): Action
    {
        return Action::make('addBooth')
            ->visible(fn (): bool => $this->desk() !== null)
            ->label('+ Tambah bilik')
            ->color('gray')
            ->size('sm')
            ->action(function (): void {
                $desk = $this->requireDesk();
                app(PermitManager::class)->assertDesk($desk, $this->user(), $desk->unit_id);

                try {
                    $booth = app(DeviceManager::class)->addBooth($desk->election, $desk->unit_id, $this->user());
                } catch (VotingException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title("{$booth->name()} ditambahkan. Klik Buat Token untuk memasang laptopnya.")->success()->send();
            });
    }

    /**
     * Hapus bilik yang belum terpasang dan belum pernah dipakai (mis. RT ini hanya punya 2 laptop bilik).
     */
    public function removeBoothAction(): Action
    {
        return Action::make('removeBooth')
            ->visible(fn (array $arguments): bool => $this->desk() !== null
                && ($booth = $this->boothFromArguments($arguments)) !== null
                && app(DeviceManager::class)->canRemoveBooth($booth))
            ->label('Hapus')
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Hapus '.($this->boothFromArguments($arguments)?->name() ?? 'bilik').'?')
            ->modalDescription('Bilik ini belum terpasang dan belum pernah dipakai. Bisa ditambah lagi kapan saja.')
            ->action(function (array $arguments): void {
                $booth = $this->boothFromArguments($arguments);
                $desk = $this->requireDesk();
                abort_if($booth === null, 404);
                app(PermitManager::class)->assertDesk($desk, $this->user(), $booth->unit_id);

                $this->run(fn () => app(DeviceManager::class)->removeBooth($booth, $this->user()), 'Bilik dihapus.');
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

    /**
     * Kartu "Silakan ke Bilik N" hilang sendiri begitu pemilih itu selesai atau izinnya berakhir (halaman diperbarui tiap 3 detik).
     */
    public function assignmentStillActive(): bool
    {
        $permitId = $this->lastAssignment['permit'] ?? null;

        if ($permitId === null) {
            return $this->lastAssignment !== null;
        }

        $active = Permit::query()->whereKey($permitId)->whereIn('status', [PermitStatus::Aktif, PermitStatus::Dipakai])->exists();

        if (! $active) {
            $this->lastAssignment = null;
        }

        return $active;
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
