<?php

namespace App\Filament\Resources\Elections\RelationManagers;

use App\Enums\StaffRole;
use App\Models\ElectionStaff;
use App\Models\User;
use App\Services\AuditLogger;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * Penugasan Panitia dan Petugas Pintu untuk pemilihan ini (K24).
 */
class StaffRelationManager extends RelationManager
{
    protected static string $relationship = 'staff';

    protected static ?string $title = 'Panitia & Petugas Pintu';

    protected static ?string $modelLabel = 'penugasan';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Akun')
                    ->options(fn (): array => User::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} ({$user->email})"])
                        ->all())
                    ->searchable()
                    ->required(),
                Select::make('role')
                    ->label('Peran')
                    ->options(StaffRole::class)
                    ->required()
                    ->unique(
                        table: 'election_staff',
                        column: 'role',
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                            ->where('election_id', $this->getOwnerRecord()->getKey())
                            ->where('user_id', $get('user_id')),
                    )
                    ->validationMessages(['unique' => 'Akun ini sudah punya peran tersebut.']),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Nama'),
                TextColumn::make('user.email')->label('Email'),
                TextColumn::make('role')->label('Peran')->badge(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Tugaskan akun')
                    ->using(function (array $data): Model {
                        $staff = new ElectionStaff($data);
                        $staff->election()->associate($this->getOwnerRecord());
                        $staff->save();

                        app(AuditLogger::class)->log('election.staff_assigned', $staff->user, $this->getOwnerRecord(), meta: ['role' => $staff->role->value]);

                        return $staff;
                    }),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Cabut')
                    ->after(fn (ElectionStaff $record) => app(AuditLogger::class)->log('election.staff_removed', $record->user, $this->getOwnerRecord(), meta: ['role' => $record->role->value])),
            ]);
    }
}
