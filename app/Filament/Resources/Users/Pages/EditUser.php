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
                ->visible(fn (): bool => (bool) $this->record->has_email_authentication)
                ->modalDescription('Pengguna harus mengaktifkan ulang kode 2FA lewat email saat login berikutnya (mis. setelah email akunnya diganti).')
                ->schema([Reauthenticate::field()])
                ->action(function (): void {
                    $this->record->forceFill(['has_email_authentication' => false])->save();
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
        $data['unit_id'] = $this->record->unit_id;
        $data['permissions'] = $this->record->permissions->pluck('name')->all();

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

        $snapshot = fn (User $user): array => [
            'role' => $user->roles()->first()?->name,
            'unit_id' => $user->unit_id,
            'permissions' => $user->permissions()->pluck('name')->all(),
            'is_active' => $user->is_active,
            'email' => $user->email,
        ];
        $before = $snapshot($record);
        $isAdminRt = $data['role'] === User::ROLE_ADMIN_RT;

        $record->fill(['name' => $data['name'], 'email' => $data['email']]);
        $record->forceFill([
            'is_active' => (bool) ($data['is_active'] ?? true),
            'unit_id' => $isAdminRt ? $data['unit_id'] : null,
        ])->save();
        $record->syncRoles([$data['role']]);
        $record->syncPermissions($isAdminRt ? ($data['permissions'] ?? []) : []);

        if (! $record->is_active) {
            DB::table('sessions')->where('user_id', $record->id)->delete();
        }

        app(AuditLogger::class)->log('user.updated', $record, meta: [
            'before' => $before,
            'after' => $snapshot($record->refresh()),
        ]);

        return $record;
    }

    private function activeSuperAdminCount(): int
    {
        return User::role(User::ROLE_SUPER_ADMIN)->where('is_active', true)->count();
    }
}
