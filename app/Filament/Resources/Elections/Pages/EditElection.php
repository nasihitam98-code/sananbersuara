<?php

namespace App\Filament\Resources\Elections\Pages;

use App\Enums\ElectionStatus;
use App\Filament\Pages\ControlRoom;
use App\Filament\Resources\Elections\ElectionResource;
use App\Filament\Support\Reauthenticate;
use App\Models\Election;
use App\Services\AuditLogger;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\VotingException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Election $record
 */
class EditElection extends EditRecord
{
    protected static string $resource = ElectionResource::class;

    /**
     * Halaman ini juga tempat tombol Status (Jeda/Tutup/Batalkan), jadi Super Admin harus tetap
     * bisa membukanya setelah pemilihan dimulai. Isian konfigurasi tetap terkunci sesuai status.
     */
    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    /**
     * Penjelasan singkat di bawah judul: apa yang dilakukan di tahap ini.
     */
    public function getSubheading(): ?string
    {
        $dadakan = $this->record->isDadakan();

        return match ($this->record->status) {
            ElectionStatus::Draft => $dadakan
                ? 'Langkah: ① tambah surat suara di bawah → ② isi calon lewat "Kelola calon" → ③ tab "Panitia & Petugas Pintu": tugaskan orangnya → ④ tekan Tandai Siap di kanan atas.'
                : 'Langkah: ① tambah surat suara Ketua RT (per RT) dan Ketua RW (semua RT) di bawah → ② isi calon lewat "Kelola calon" → ③ tekan Tandai Siap di kanan atas.',
            ElectionStatus::Ready => 'Sudah Siap. Calon masih bisa diubah. Saat acara benar-benar dimulai, tekan Mulai Pemilihan di kanan atas.',
            ElectionStatus::Berlangsung, ElectionStatus::Paused => 'Pemilihan sedang berlangsung, jadi isian di halaman ini dikunci agar tidak berubah di tengah acara. Untuk menjeda atau menutup: tombol Status di kanan atas.',
            default => 'Pemilihan sudah ditutup; isian dikunci. Lanjutkan dari menu Layar Hasil dan Verifikasi & Publikasi.',
        };
    }

    protected function getFormActions(): array
    {
        return $this->isConfigurable() ? parent::getFormActions() : [];
    }

    private function isConfigurable(): bool
    {
        return $this->record->status->allowsConfigurationChanges();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('controlRoom')
                ->label('Buka Ruang Kendali')
                ->icon('heroicon-o-play-circle')
                ->url(fn (): string => ControlRoom::getUrl(['pemilihan' => $this->record->public_id]))
                ->visible(fn (): bool => $this->record->isDadakan() && $this->record->status !== ElectionStatus::Draft),

            Action::make('markReady')
                ->label('Tandai Siap')
                ->icon('heroicon-o-check-badge')
                ->color('info')
                ->visible(fn (): bool => $this->record->status === ElectionStatus::Draft)
                ->requiresConfirmation()
                ->modalHeading('Tandai pemilihan Siap?')
                ->modalDescription(fn (): string => $this->readinessText())
                ->action(fn () => $this->runLifecycle(fn (ElectionLifecycle $lifecycle) => $lifecycle->markReady($this->record, auth()->user()), 'Pemilihan ditandai Siap.')),

            Action::make('backToDraft')
                ->label('Kembali ke Draf')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === ElectionStatus::Ready)
                ->requiresConfirmation()
                ->action(fn () => $this->runLifecycle(fn (ElectionLifecycle $lifecycle) => $lifecycle->backToDraft($this->record, auth()->user()), 'Pemilihan kembali ke Draf.')),

            Action::make('start')
                ->label('Mulai Pemilihan')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === ElectionStatus::Ready)
                ->modalHeading('Mulai pemilihan?')
                ->modalDescription('Surat suara dan kandidat terkunci setelah dimulai. Voting di HP baru terbuka saat Anda membuka gelombang di Ruang Kendali.')
                ->schema([Reauthenticate::field()])
                ->action(fn () => $this->runLifecycle(fn (ElectionLifecycle $lifecycle) => $lifecycle->start($this->record, auth()->user()), 'Pemilihan dimulai.')),

            ActionGroup::make([
                Action::make('pause')
                    ->label('Jeda Pemilihan')
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->visible(fn (): bool => $this->record->status === ElectionStatus::Berlangsung)
                    ->schema([Textarea::make('note')->label('Alasan jeda')->required()->maxLength(500)])
                    ->action(fn (array $data) => $this->runLifecycle(fn (ElectionLifecycle $lifecycle) => $lifecycle->pause($this->record, auth()->user(), $data['note']), 'Pemilihan dijeda.')),

                Action::make('resume')
                    ->label('Lanjutkan Pemilihan')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (): bool => $this->record->status === ElectionStatus::Paused)
                    ->requiresConfirmation()
                    ->action(fn () => $this->runLifecycle(fn (ElectionLifecycle $lifecycle) => $lifecycle->resume($this->record, auth()->user()), 'Pemilihan dilanjutkan.')),

                Action::make('close')
                    ->label('Tutup Pemilihan')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->visible(fn (): bool => $this->record->status->isLive())
                    ->modalHeading('Tutup pemilihan?')
                    ->modalDescription('Tidak ada suara baru yang diterima. Pulihkan Hak Pilih tidak bisa lagi dilakukan karena tautan sementara peserta-suara dihapus permanen. Tindakan ini tidak bisa dibatalkan.')
                    ->schema([Reauthenticate::field()])
                    ->action(fn () => $this->runLifecycle(fn (ElectionLifecycle $lifecycle) => $lifecycle->close($this->record, auth()->user()), 'Pemilihan ditutup.')),

                Action::make('cancel')
                    ->label('Batalkan Pemilihan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (): bool => in_array($this->record->status, [ElectionStatus::Ready, ElectionStatus::Berlangsung, ElectionStatus::Paused, ElectionStatus::Ditutup, ElectionStatus::Verifikasi], true))
                    ->modalHeading('Batalkan pemilihan?')
                    ->modalDescription('Data dan suara tetap tersimpan, tetapi pemilihan tidak bisa dilanjutkan.')
                    ->schema([
                        Textarea::make('note')->label('Alasan pembatalan')->required()->maxLength(500),
                        Reauthenticate::field(),
                    ])
                    ->action(fn (array $data) => $this->runLifecycle(fn (ElectionLifecycle $lifecycle) => $lifecycle->cancel($this->record, auth()->user(), $data['note']), 'Pemilihan dibatalkan.')),
            ])->label('Status')->icon('heroicon-o-adjustments-horizontal')->button()->color('gray'),

            DeleteAction::make()->label('Hapus draf'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless(static::getResource()::canEdit($record), 403);

        $before = $record->only(['name', 'settings']);
        $data['settings'] = ElectionResource::mergeSettings($data['settings'] ?? null, $record->settings);
        $record->update($data);

        app(AuditLogger::class)->log('election.updated', $record, $record, meta: [
            'before' => $before,
            'after' => $record->only(['name', 'settings']),
        ]);

        return $record;
    }

    private function readinessText(): string
    {
        $lifecycle = app(ElectionLifecycle::class);
        $problems = $lifecycle->readinessProblems($this->record);
        $warnings = $lifecycle->readinessWarnings($this->record);

        if ($problems !== []) {
            return 'Belum bisa: '.implode(' ', $problems);
        }

        return $warnings === [] ? 'Semua syarat terpenuhi.' : 'Catatan: '.implode(' ', $warnings);
    }

    /**
     * @param  callable(ElectionLifecycle): void  $callback
     */
    private function runLifecycle(callable $callback, string $successMessage): void
    {
        try {
            $callback(app(ElectionLifecycle::class));
        } catch (VotingException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($successMessage)->success()->send();
        $this->record->refresh();
        $this->refreshFormData(['name', 'mode', 'settings']);
    }
}
