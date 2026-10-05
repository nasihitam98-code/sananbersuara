<?php

namespace App\Filament\Pages\Auth;

use App\Services\AuditLogger;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * Profil + ganti password. Mengganti password menghapus tanda "wajib ganti password".
 */
class EditProfile extends BaseEditProfile
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        $passwordChanged = filled($data['password'] ?? null);

        $record = parent::handleRecordUpdate($record, $data);

        if ($passwordChanged) {
            $record->forceFill(['must_change_password' => false])->save();
            app(AuditLogger::class)->log('user.password_changed', $record, actor: $record);
        }

        return $record;
    }
}
