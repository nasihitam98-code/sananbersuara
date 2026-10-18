<?php

namespace App\Filament\Resources\Elections\RelationManagers;

use App\Enums\BallotScope;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Filament\Resources\Elections\Pages\EditElection;
use App\Models\Ballot;
use App\Models\Election;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BallotsRelationManager extends RelationManager
{
    protected static string $relationship = 'ballots';

    /** Dimuat langsung (bukan saat digulir) agar tombol "Kelola surat suara" tidak berakhir di kotak kosong. */
    protected static bool $isLazy = false;

    protected static ?string $title = 'Surat suara';

    protected static ?string $modelLabel = 'surat suara';

    public function form(Schema $schema): Schema
    {
        /** @var Election $election */
        $election = $this->getOwnerRecord();

        return $schema
            ->components([
                TextInput::make('title')
                    ->label('Judul surat suara')
                    ->placeholder($election->isDadakan() ? 'Contoh: Calon Ketua RW' : 'Contoh: Ketua RT / Ketua RW')
                    ->required()
                    ->maxLength(150),
                Select::make('scope')
                    ->label('Siapa yang berhak memilih')
                    // Cukup dua pilihan (permintaan pemilik). "RT tertentu saja" tetap didukung untuk data lama/putaran ulang,
                    // jadi hanya muncul bila surat suara yang diubah memang memakainya.
                    ->options(fn (?Ballot $record): array => array_filter([
                        BallotScope::PerRt->value => 'Per RT: tiap RT punya calon sendiri (untuk Ketua RT)',
                        BallotScope::SemuaRt->value => 'Semua RT: calon sama untuk semua warga (untuk Ketua RW)',
                        BallotScope::RtTertentu->value => $record?->scope === BallotScope::RtTertentu ? BallotScope::RtTertentu->getLabel() : null,
                    ]))
                    ->visible(! $election->isDadakan())
                    ->required(! $election->isDadakan())
                    ->live(),
                Select::make('units')
                    ->label('RT yang berhak')
                    ->relationship('units', 'name')
                    ->multiple()
                    ->preload()
                    ->visible(fn (Get $get): bool => in_array($get('scope'), [BallotScope::RtTertentu, BallotScope::RtTertentu->value], true))
                    ->required(fn (Get $get): bool => in_array($get('scope'), [BallotScope::RtTertentu, BallotScope::RtTertentu->value], true)),
                TextInput::make('max_candidates')
                    ->label(fn (Get $get): string => in_array($get('scope'), [BallotScope::PerRt, BallotScope::PerRt->value], true) ? 'Batas jumlah calon per RT (opsional)' : 'Batas jumlah calon (opsional)')
                    ->numeric()->minValue(1)->maxValue(100),
                TextInput::make('sort')
                    ->label('Urutan tampil')
                    ->helperText('Surat suara dengan angka lebih kecil tampil lebih dulu di bilik (mis. Ketua RT = 1, Ketua RW = 2).')
                    ->numeric()
                    ->default(fn (): int => (int) $election->ballots()->max('sort') + 1)
                    ->visible(! $election->isDadakan()),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('sort')->label('#'),
                TextColumn::make('title')->label('Judul')->weight('bold'),
                TextColumn::make('scope')->label('Cakupan')->badge(),
                TextColumn::make('candidates_count')->label('Calon')->counts('candidates'),
                TextColumn::make('max_candidates')->label('Batas')->placeholder('-'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Tambah surat suara')
                    ->createAnother(false)
                    // Surat suara hanya bisa ditambah selama Draf/Siap (bukan saat berlangsung, ditutup, atau dibatalkan).
                    ->visible(fn (): bool => $this->getOwnerRecord()->status->allowsConfigurationChanges())
                    ->using(function (array $data): Model {
                        /** @var Election $election */
                        $election = $this->getOwnerRecord();
                        abort_unless($election->status->allowsConfigurationChanges(), 403);
                        $ballot = new Ballot($data);
                        $ballot->scope = $election->isDadakan() ? BallotScope::DaftarHadir : ($data['scope'] ?? BallotScope::SemuaRt);
                        $ballot->sort ??= (int) $election->ballots()->max('sort') + 1;
                        $ballot->election()->associate($election);
                        $ballot->save();

                        app(AuditLogger::class)->log('ballot.created', $ballot, $election, meta: ['title' => $ballot->title]);
                        $this->dispatch(EditElection::PREPARATION_UPDATED);

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
                    ->after(function (Ballot $record): void {
                        app(AuditLogger::class)->log('ballot.deleted', null, $record->election, meta: ['title' => $record->title]);
                        $this->dispatch(EditElection::PREPARATION_UPDATED);
                    }),
            ]);
    }
}
