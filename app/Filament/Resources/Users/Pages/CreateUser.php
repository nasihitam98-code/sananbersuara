<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = new User($data);
        $user->forceFill(['must_change_password' => true, 'is_active' => true])->save();
        $user->syncRoles([$data['role']]);

        app(AuditLogger::class)->log('user.created', $user, meta: ['role' => $data['role']]);

        return $user;
    }
}
