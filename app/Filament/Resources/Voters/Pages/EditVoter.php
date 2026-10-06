<?php

namespace App\Filament\Resources\Voters\Pages;

use App\Filament\Resources\Voters\VoterResource;
use App\Models\Voter;
use App\Services\Voters\VoterRegistry;
use App\Services\Voting\VotingException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditVoter extends EditRecord
{
    protected static string $resource = VoterResource::class;

    /**
     * NIK tidak pernah dimuat ke form (tidak tersimpan penuh).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['nik_hash'], $data['phone']);
        $data['phone'] = $this->getRecord()->phone;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Voter $record */
        try {
            return app(VoterRegistry::class)->update(
                $record,
                collect($data)->only(['name', 'address', 'gender', 'birth_date', 'phone'])->all(),
                auth()->user(),
                $data['nik'] ?? null,
                filled($data['nik'] ?? null),
            );
        } catch (VotingException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }
}
