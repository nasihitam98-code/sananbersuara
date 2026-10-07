<?php

namespace App\Filament\Pages;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\StaffRole;
use App\Enums\WaveKind;
use App\Enums\WaveStatus;
use App\Filament\Pages\Concerns\InteractsWithAttendanceTable;
use App\Filament\Pages\Concerns\InteractsWithElection;
use App\Filament\Support\QuickStart;
use App\Filament\Support\Reauthenticate;
use App\Filament\Support\Workspace;
use App\Models\Election;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\ResultsCalculator;
use App\Services\Voting\VoterStatus;
use App\Services\Voting\VotingException;
use App\Services\Voting\WaveManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Ruang Kendali Panitia (Mode Dadakan): buka/perpanjang/tutup gelombang, pantau sudah/belum
 * (tanpa angka per kandidat), dan tabel peserta: siapa sudah/belum memilih, PIN baru / Pulihkan, unduh Excel.
 */
class ControlRoom extends Page implements HasTable
{
    use InteractsWithAttendanceTable;
    use InteractsWithElection;
    use InteractsWithTable;

    protected string $view = 'filament.pages.control-room';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Hari H';

    protected static ?string $navigationLabel = 'Ruang Kendali';

    protected static ?string $title = 'Ruang Kendali Voting';

    protected static ?string $slug = 'ruang-kendali';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        return Workspace::showsInElection(ElectionMode::Dadakan);
    }

    protected static function allowedStaffRoles(): array
    {
        return [StaffRole::Panitia];
    }

    protected static function allowedStatuses(): array
    {
        return [
            ElectionStatus::Ready,
            ElectionStatus::Berlangsung,
            ElectionStatus::Paused,
            ElectionStatus::Ditutup,
            // Setelah ditutup: hanya lihat & unduh daftar peserta (arsip), tombol voting tidak tampil.
            ElectionStatus::Verifikasi,
            ElectionStatus::Published,
            ElectionStatus::Unpublished,
            ElectionStatus::Cancelled,
            ElectionStatus::Archived,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $election = $this->election();

        if ($election === null) {
            return [];
        }

        $round = $election->currentRound();

        return [
            'status' => app(VoterStatus::class)->for($election),
            'participation' => app(ResultsCalculator::class)->participation($election, $round),
            'round' => $round,
            'wave' => $election->openWave(),
            'waves' => $round?->waves()->get() ?? collect(),
            'voterUrl' => route('voter.show', $election->access_code),
            'headcount' => $election->setting('headcount'),
        ];
    }

    public function table(Table $table): Table
    {
        return $this->attendanceTable($table)
            ->heading('Peserta')
            ->defaultPaginationPageOption(25);
    }

    /**
     * Super Admin menutup pemilihan dari sini setelah semua sesi selesai; setelah itu Layar Hasil terbuka.
     */
    public function closeElectionAction(): Action
    {
        return Action::make('closeElection')
            ->label('Tutup Pemilihan')
            ->icon('heroicon-o-lock-closed')
            ->color('gray')
            ->outlined()
            ->visible(fn (): bool => ($this->election()?->status->isLive() ?? false) && $this->isSuperAdmin())
            ->modalHeading('Tutup pemilihan?')
            ->modalDescription(fn (): string => $this->closeElectionWarning()
                .'Tidak ada suara baru yang diterima dan Pulihkan Hak Pilih tidak bisa lagi dilakukan. Setelah ditutup, hasil bisa ditampilkan di Layar Hasil. Tindakan ini tidak bisa dibatalkan.')
            ->modalSubmitActionLabel('Ya, tutup pemilihan')
            ->schema([Reauthenticate::field()])
            ->action(function (): void {
                abort_unless($this->isSuperAdmin(), 403);

                $this->guard(fn () => app(ElectionLifecycle::class)->close($this->authorizedElection(), auth()->user()), 'Pemilihan ditutup. Buka Layar Hasil untuk menampilkan hasil.');
            });
    }

    private function closeElectionWarning(): string
    {
        $election = $this->election();

        if ($election === null) {
            return '';
        }

        $notVoted = app(ResultsCalculator::class)->participation($election, $election->currentRound())['not_voted'];
        $warnings = [];

        if ($election->openWave() !== null) {
            $warnings[] = 'Voting masih terbuka dan akan ikut ditutup.';
        }

        if ($notVoted > 0) {
            $warnings[] = "Masih ada {$notVoted} orang yang belum memilih.";
        }

        return $warnings === [] ? '' : '⚠ '.implode(' ', $warnings).' ';
    }

    /**
     * Super Admin bisa memulai pemilihan langsung dari sini agar tidak bolak-balik ke menu Pemilihan.
     * Panitia tidak: memulai pemilihan mengunci surat suara dan calon.
     */
    public function startElectionAction(): Action
    {
        return QuickStart::action('startElection', fn (array $arguments): ?Election => $this->election());
    }

    private function isSuperAdmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    public function openWaveAction(): Action
    {
        return Action::make('openWave')
            ->label('BUKA VOTING')
            ->icon('heroicon-o-play')
            ->color('success')
            ->size('xl')
            ->visible(fn (): bool => $this->election()?->status === ElectionStatus::Berlangsung && $this->election()?->openWave() === null)
            ->modalHeading('Buka sesi voting')
            ->modalSubmitActionLabel('Buka voting')
            ->schema(function (): array {
                $round = $this->election()?->currentRound();
                $nextNumber = (int) ($round?->waves()->max('number') ?? 0) + 1;

                return [
                    TextInput::make('name')
                        ->label('Nama sesi (opsional)')
                        ->placeholder("Gelombang {$nextNumber}")
                        ->helperText($nextNumber > 1
                            ? 'Sesi ini untuk warga yang BELUM memilih. Yang sudah memilih tidak bisa memilih lagi. Kosongkan = Gelombang '.$nextNumber.'.'
                            : 'Mis. "Sesi pertama", "Sesi lansia", "Susulan RT 05". Kosongkan = Gelombang 1.')
                        ->maxLength(60),
                    Toggle::make('timed')
                        ->label('Pakai timer')
                        ->helperText('Matikan bila ingin buka-tutup manual: voting terbuka sampai Anda menekan Tutup Sekarang.')
                        ->default(true)
                        ->live(),
                    TextInput::make('minutes')
                        ->label('Durasi (menit)')
                        ->numeric()->minValue(1)->maxValue(120)
                        ->default(fn (): int => $this->election()?->defaultWaveMinutes() ?? 5)
                        ->visible(fn (Get $get): bool => (bool) $get('timed'))
                        ->required(fn (Get $get): bool => (bool) $get('timed')),
                ];
            })
            ->action(function (array $data): void {
                // Semua sesi sama: siapa pun yang belum memilih boleh memilih, dari HP mana pun.
                $kind = WaveKind::Terbuka;
                $minutes = ($data['timed'] ?? false) && filled($data['minutes'] ?? null) ? (int) $data['minutes'] : null;

                $this->guard(fn () => app(WaveManager::class)->open($this->authorizedElection(), $kind, $minutes, auth()->user(), $data['name'] ?? null), 'Voting dibuka.');
            });
    }

    public function extendWaveAction(): Action
    {
        return Action::make('extendWave')
            ->label(fn (): string => $this->election()?->openWave()?->hasTimer() === false ? 'Pasang timer' : 'Perpanjang')
            ->icon('heroicon-o-clock')
            ->color('warning')
            ->visible(fn (): bool => $this->election()?->openWave() !== null)
            ->modalHeading(fn (): string => $this->election()?->openWave()?->hasTimer() === false ? 'Pasang timer' : 'Perpanjang waktu voting')
            ->modalDescription(fn (): ?string => $this->election()?->openWave()?->hasTimer() === false ? 'Voting tertutup otomatis setelah waktu ini habis.' : null)
            ->schema([
                Select::make('minutes')->label('Waktu')->options([1 => '+1 menit', 2 => '+2 menit', 3 => '+3 menit', 5 => '+5 menit', 10 => '+10 menit'])->default(2)->required(),
            ])
            ->action(fn (array $data) => $this->guard(fn () => app(WaveManager::class)->extend($this->authorizedElection(), (int) $data['minutes'], auth()->user()), 'Waktu diperpanjang.'));
    }

    public function closeWaveAction(): Action
    {
        return Action::make('closeWave')
            ->label('Tutup Sekarang')
            ->icon('heroicon-o-stop')
            ->color('danger')
            ->visible(fn (): bool => $this->election()?->openWave() !== null)
            ->requiresConfirmation()
            ->modalHeading('Tutup gelombang sekarang?')
            ->modalDescription('Pemilih yang sudah menekan konfirmasi dalam beberapa detik terakhir tetap dihitung.')
            ->action(fn () => $this->guard(fn () => app(WaveManager::class)->close($this->authorizedElection(), auth()->user()), 'Gelombang ditutup.'));
    }

    public function headcountAction(): Action
    {
        return Action::make('headcount')
            ->label('Input hitung kepala')
            ->icon('heroicon-o-users')
            ->color('gray')
            ->schema([
                TextInput::make('headcount')->label('Jumlah orang hadir (hitung manual)')->numeric()->minValue(0)->maxValue(100000)->required(),
            ])
            ->action(function (array $data): void {
                $election = $this->authorizedElection();
                $settings = $election->settings ?? [];
                $settings['headcount'] = (int) $data['headcount'];
                $election->settings = $settings;
                $election->save();

                app(AuditLogger::class)->log('election.headcount_entered', $election, $election, meta: ['headcount' => (int) $data['headcount']]);
                Notification::make()->title('Hitung kepala disimpan.')->success()->send();
            });
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

    public function waveStatusLabel(?WaveStatus $status): string
    {
        return match ($status) {
            WaveStatus::Dibuka => 'Dibuka',
            WaveStatus::Dijeda => 'Dijeda',
            WaveStatus::Ditutup => 'Ditutup',
            default => '-',
        };
    }
}
