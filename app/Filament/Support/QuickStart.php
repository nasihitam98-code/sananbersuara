<?php

namespace App\Filament\Support;

use App\Enums\ElectionStatus;
use App\Enums\WaveKind;
use App\Models\Election;
use App\Models\User;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\VotingException;
use App\Services\Voting\WaveManager;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;

/**
 * "Mulai Pemilihan" satu langkah (Super Admin): periksa kelengkapan, tandai Siap bila masih Draf, mulai,
 * dan (Mode Dadakan) langsung buka sesi voting pertama. Dipakai di Beranda dan Ruang Kendali.
 */
class QuickStart
{
    /**
     * @param  Closure(array<string, mixed>): ?Election  $election  menerima argumen aksi (mis. pemilihan pada kartu)
     * @param  (Closure(Election): void)|null  $after
     */
    public static function action(string $name, Closure $election, ?Closure $after = null): Action
    {
        $isSuperAdmin = fn (): bool => ($user = auth()->user()) instanceof User && $user->isSuperAdmin();

        return Action::make($name)
            ->label('Mulai Pemilihan')
            ->icon('heroicon-o-play')
            ->color('success')
            ->size('xl')
            ->visible(fn (array $arguments): bool => $isSuperAdmin() && in_array($election($arguments)?->status, [ElectionStatus::Draft, ElectionStatus::Ready], true))
            ->modalHeading(fn (array $arguments): string => 'Mulai "'.($election($arguments)?->name ?? '').'"?')
            ->modalDescription('Calon dan surat suara dikunci setelah dimulai. Tidak bisa dibatalkan.')
            ->modalSubmitActionLabel('Mulai sekarang')
            ->schema(fn (array $arguments): array => array_values(array_filter([
                Reauthenticate::field(),
                $election($arguments)?->isDadakan() ? Toggle::make('open_now')
                    ->label('Langsung buka voting')
                    ->helperText('HP warga bisa langsung memilih. Matikan bila ingin membuka voting nanti dari Ruang Kendali.')
                    ->default(true)
                    ->live() : null,
                $election($arguments)?->isDadakan() ? TextInput::make('minutes')
                    ->label('Durasi (menit)')
                    ->helperText('Kosongkan = tanpa timer, tutup manual dari Ruang Kendali.')
                    ->numeric()->minValue(1)->maxValue(120)
                    ->default(fn (): int => $election($arguments)?->defaultWaveMinutes() ?? 5)
                    ->visible(fn (Get $get): bool => (bool) $get('open_now')) : null,
            ])))
            ->action(function (array $data, array $arguments) use ($election, $isSuperAdmin, $after): void {
                $record = $election($arguments);
                abort_unless($record !== null && $isSuperAdmin(), 403);

                /** @var User $user */
                $user = auth()->user();
                $lifecycle = app(ElectionLifecycle::class);

                try {
                    if ($record->status === ElectionStatus::Draft) {
                        $lifecycle->markReady($record, $user);
                    }

                    $lifecycle->start($record->fresh(), $user);

                    $openNow = $record->isDadakan() && ($data['open_now'] ?? false);

                    if ($openNow) {
                        $minutes = filled($data['minutes'] ?? null) ? (int) $data['minutes'] : null;
                        app(WaveManager::class)->open($record->fresh(), WaveKind::Terbuka, $minutes, $user);
                    }
                } catch (VotingException $exception) {
                    Notification::make()->title('Belum bisa dimulai')->body($exception->getMessage())->danger()->persistent()->send();

                    return;
                }

                Notification::make()
                    ->title($openNow ? 'Pemilihan dimulai dan voting dibuka.' : 'Pemilihan dimulai.')
                    ->body($openNow ? 'Tampilkan Layar QR agar warga bisa memilih.' : 'Buka voting dari Ruang Kendali saat siap.')
                    ->success()
                    ->send();

                if ($after !== null) {
                    $after($record->fresh());
                }
            });
    }
}
