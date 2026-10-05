<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Reauthenticate;
use App\Models\User;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * @property User $record
 */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetPassword')
                ->label('Reset password')
                ->color('warning')
                ->schema([
                    TextInput::make('password')->label('Password sementara baru')->password()->revealable()->required()->rule(Password::default()),
                    Reauthenticate::field(),
                ])
                ->action(function (array $data): void {
                    $this->record->forceFill(['password' => $data['password'], 'must_change_password' => true])->save();
                    DB::table('sessions')->where('user_id', $this->record->id)->delete();
                    app(AuditLogger::class)->log('user.password_reset_by_admin', $this->record);
                    Notification::make()->title('Password direset. Semua sesi akun ini dikeluarkan.')->success()->send();
                }),
            Action::make('resetTwoFactor')
                ->label('Reset 2FA')
                ->color('danger')
                ->visible(fn (): bool => filled($this->record->app_authentication_secret))
                ->modalDescription('Pengguna harus memasang ulang aplikasi authenticator saat login berikutnya.')
                ->schema([Reauthenticate::field()])
                ->action(function (): void {
                    $this->record->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
                    DB::table('sessions')->where('user_id', $this->record->id)->delete();
                    app(AuditLogger::class)->log('user.two_factor_reset_by_admin', $this->record);
                    Notification::make()->title('2FA direset.')->success()->send();
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['role'] = $this->record->roles->first()?->name;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $wasSuperAdmin = $record->isSuperAdmin() && $record->is_active;
        $staysSuperAdmin = $data['role'] === User::ROLE_SUPER_ADMIN && ($data['is_active'] ?? true);

        if ($wasSuperAdmin && ! $staysSuperAdmin && $this->activeSuperAdminCount() <= 2) {
            Notification::make()
                ->title('Ditolak: minimal harus ada 2 Super Admin aktif.')
                ->danger()
                ->send();

            throw new Halt;
        }

        $before = ['role' => $record->roles->first()?->name, 'is_active' => $record->is_active, 'email' => $record->email];

        $record->fill(['name' => $data['name'], 'email' => $data['email']]);
        $record->forceFill(['is_active' => (bool) ($data['is_active'] ?? true)])->save();
        $record->syncRoles([$data['role']]);

        if (! $record->is_active) {
            DB::table('sessions')->where('user_id', $record->id)->delete();
        }

        app(AuditLogger::class)->log('user.updated', $record, meta: [
            'before' => $before,
            'after' => ['role' => $data['role'], 'is_active' => $record->is_active, 'email' => $record->email],
        ]);

        return $record;
    }

    private function activeSuperAdminCount(): int
    {
        return User::role(User::ROLE_SUPER_ADMIN)->where('is_active', true)->count();
    }
}
