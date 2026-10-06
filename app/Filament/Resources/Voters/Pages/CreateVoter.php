<?php

namespace App\Filament\Resources\Voters\Pages;

use App\Enums\EmergencyAddReason;
use App\Filament\Resources\Voters\VoterResource;
use App\Models\User;
use App\Services\Voters\DuplicateNameWarning;
use App\Services\Voters\VoterRegistry;
use App\Services\Voting\VotingException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateVoter extends CreateRecord
{
    protected static string $resource = VoterResource::class;

    protected bool $createAnother = true;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        // Admin RT selalu RT sendiri; nilai RT dari form hanya dipakai untuk Super Admin.
        $unitId = $user->isSuperAdmin() ? (int) ($data['unit_id'] ?? 0) : (int) $user->unit_id;

        try {
            return app(VoterRegistry::class)->create(
                collect($data)->only(['name', 'address', 'gender', 'birth_date', 'phone'])->all(),
                $unitId,
                $user,
                $data['nik'] ?? null,
                (bool) ($data['confirm_different_person'] ?? false),
                ($data['emergency_reason'] ?? null) instanceof EmergencyAddReason ? $data['emergency_reason']->value : ($data['emergency_reason'] ?? null),
            );
        } catch (DuplicateNameWarning $warning) {
            Notification::make()
                ->title('Nama yang sama sudah terdaftar')
                ->body(implode("\n", $warning->similar)."\n\nJika ini orang berbeda, centang konfirmasi lalu simpan lagi.")
                ->warning()
                ->persistent()
                ->send();
        } catch (VotingException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->persistent()->send();
        }

        throw new Halt;
    }
}
