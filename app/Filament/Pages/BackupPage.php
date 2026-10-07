<?php

namespace App\Filament\Pages;

use App\Filament\Support\Reauthenticate;
use App\Models\Backup;
use App\Models\User;
use App\Services\Backups\BackupService;
use App\Services\Voting\VotingException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use UnitEnum;

/**
 * Backup & Restore (K27, Super Admin): riwayat, Backup Sekarang, unduh, restore terkontrol.
 */
class BackupPage extends Page
{
    protected string $view = 'filament.pages.backup-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Lainnya';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Backup & Restore';

    protected static ?string $title = 'Backup & Restore';

    protected static ?string $slug = 'backup';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    /**
     * @return Collection<int, Backup>
     */
    public function backups(): Collection
    {
        return Backup::query()->with('creator')->latest()->limit(100)->get();
    }

    public function offsiteConfigured(): bool
    {
        return filled(config('voting.backup.offsite_disk'));
    }

    public function backupNowAction(): Action
    {
        return Action::make('backupNow')
            ->label('Backup Sekarang')
            ->icon('heroicon-o-arrow-down-on-square-stack')
            ->schema([Reauthenticate::field()])
            ->action(function (): void {
                $backup = app(BackupService::class)->create('MANUAL', auth()->user());

                $backup->status === Backup::STATUS_SUCCESS
                    ? Notification::make()->title('Backup berhasil: '.$backup->filename)->success()->send()
                    : Notification::make()->title('Backup gagal: '.$backup->error)->danger()->persistent()->send();
            });
    }

    public function downloadAction(): Action
    {
        return Action::make('download')
            ->label('Unduh')
            ->size('sm')
            ->color('gray')
            ->modalDescription('File tetap terenkripsi. Untuk membukanya perlu password backup (BACKUP_PASSWORD).')
            ->schema([Reauthenticate::field()])
            ->action(function (array $arguments): void {
                $backup = $this->backupFromArguments($arguments);

                // Tautan bertanda tangan, berlaku 2 menit, hanya untuk akun ini.
                $this->redirect(URL::temporarySignedRoute('backups.download', now()->addMinutes(2), ['backup' => $backup->public_id, 'user' => auth()->id()]));
            });
    }

    public function restoreAction(): Action
    {
        return Action::make('restore')
            ->label('Pulihkan')
            ->size('sm')
            ->color('danger')
            ->modalHeading('Pulihkan database dari backup ini?')
            ->modalDescription('SEMUA data saat ini diganti dengan isi backup. Sistem otomatis membuat backup pengaman dulu. Ditolak bila ada pemilihan berlangsung.')
            ->schema([
                TextInput::make('confirmation')->label('Ketik PULIHKAN untuk melanjutkan')->required(),
                Reauthenticate::field(),
            ])
            ->action(function (array $data, array $arguments): void {
                try {
                    app(BackupService::class)->restore($this->backupFromArguments($arguments), auth()->user(), $data['confirmation']);
                } catch (VotingException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()->title('Database dipulihkan. Silakan login ulang bila diminta.')->success()->persistent()->send();
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function backupFromArguments(array $arguments): Backup
    {
        return Backup::query()->where('public_id', $arguments['backup'] ?? '')->where('status', Backup::STATUS_SUCCESS)->firstOrFail();
    }
}
