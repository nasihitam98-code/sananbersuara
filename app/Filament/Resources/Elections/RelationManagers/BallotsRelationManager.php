<?php

namespace App\Filament\Resources\Elections\RelationManagers;

use App\Enums\BallotScope;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Models\Ballot;
use App\Models\Election;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BallotsRelationManager extends RelationManager
{
    protected static string $relationship = 'ballots';

    protected static ?string $title = 'Surat suara';

    protected static ?string $modelLabel = 'surat suara';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label('Judul surat suara')
                    ->placeholder('Contoh: Calon Ketua RW')
                    ->required()
                    ->maxLength(150),
                TextInput::make('max_candidates')
                    ->label('Batas jumlah calon (opsional)')
                    ->numeric()->minValue(1)->maxValue(100),
                TextInput::make('sort')
                    ->label('Urutan')
                    ->numeric()->default(0),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('sort')->label('#'),
                TextColumn::make('title')->label('Judul')->weight('bold'),
                TextColumn::make('candidates_count')->label('Calon')->counts('candidates'),
                TextColumn::make('max_candidates')->label('Batas')->placeholder('-'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Tambah surat suara')
                    ->using(function (array $data): Model {
                        /** @var Election $election */
                        $election = $this->getOwnerRecord();
                        $ballot = new Ballot($data);
                        $ballot->scope = $election->isDadakan() ? BallotScope::DaftarHadir : BallotScope::SemuaRt;
                        $ballot->election()->associate($election);
                        $ballot->save();

                        app(AuditLogger::class)->log('ballot.created', $ballot, $election, meta: ['title' => $ballot->title]);

                        return $ballot;
                    }),
            ])
            ->recordActions([
                Action::make('candidates')
                    ->label('Kelola calon')
                    ->icon('heroicon-o-user-group')
                    ->url(fn (Ballot $record): string => CandidateResource::getUrl('index', ['filters' => ['ballot_id' => ['value' => $record->id]]])),
                EditAction::make()
                    ->after(fn (Ballot $record) => app(AuditLogger::class)->log('ballot.updated', $record, $record->election, meta: $record->only(['title', 'max_candidates', 'sort']))),
                DeleteAction::make()
                    ->after(fn (Ballot $record) => app(AuditLogger::class)->log('ballot.deleted', null, $record->election, meta: ['title' => $record->title])),
            ]);
    }
}
