<?php

namespace App\Filament\Resources\Candidates;

use App\Enums\BallotScope;
use App\Enums\CandidateStatus;
use App\Enums\ElectionStatus;
use App\Filament\Resources\Candidates\Pages\CreateCandidate;
use App\Filament\Resources\Candidates\Pages\EditCandidate;
use App\Filament\Resources\Candidates\Pages\ListCandidates;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class CandidateResource extends Resource
{
    protected static ?string $model = Candidate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Persiapan';

    protected static ?string $modelLabel = 'calon';

    protected static ?string $pluralModelLabel = 'Calon';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    /**
     * Surat suara yang masih boleh diisi kandidat (pemilihan Draf/Siap).
     *
     * @return array<int, string>
     */
    public static function editableBallotOptions(): array
    {
        return Ballot::query()
            ->with('election')
            ->whereHas('election', fn (Builder $query) => $query->whereIn('status', [ElectionStatus::Draft, ElectionStatus::Ready]))
            ->get()
            ->mapWithKeys(fn (Ballot $ballot): array => [$ballot->id => "{$ballot->election->name} — {$ballot->title}"])
            ->all();
    }

    public static function ballotIsPerUnit(mixed $ballotId): bool
    {
        return filled($ballotId) && Ballot::query()->whereKey($ballotId)->where('scope', BallotScope::PerRt)->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data calon')
                    ->columns(2)
                    ->schema([
                        Select::make('ballot_id')
                            ->label('Surat suara')
                            ->options(fn (): array => static::editableBallotOptions())
                            ->default(fn (): ?int => request()->integer('surat_suara') ?: null)
                            ->required()
                            ->in(fn (): array => array_keys(static::editableBallotOptions()))
                            ->live()
                            ->visibleOn('create'),
                        Select::make('unit_id')
                            ->label('RT calon')
                            ->options(fn (): array => Unit::query()->orderBy('sort')->pluck('name', 'id')->all())
                            ->helperText('Surat suara per RT: calon hanya tampil untuk pemilih RT ini.')
                            ->visible(fn (Get $get, ?Candidate $record): bool => static::ballotIsPerUnit($record?->ballot_id ?? $get('ballot_id')))
                            ->required(fn (Get $get, ?Candidate $record): bool => static::ballotIsPerUnit($record?->ballot_id ?? $get('ballot_id')))
                            ->disabledOn('edit')
                            ->live(),
                        TextInput::make('number')
                            ->label('Nomor urut')
                            ->numeric()->minValue(1)->maxValue(999)
                            ->required()
                            ->unique(
                                table: 'candidates',
                                column: 'number',
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule, Get $get, ?Candidate $record): Unique => $rule
                                    ->where('ballot_id', $record?->ballot_id ?? $get('ballot_id'))
                                    ->where('unit_id', $record?->unit_id ?? ($get('unit_id') ?: null)),
                            )
                            ->validationMessages(['unique' => 'Nomor urut ini sudah dipakai calon lain di surat suara (dan RT) yang sama.']),
                        TextInput::make('name')
                            ->label('Nama lengkap (tampil di surat suara)')
                            ->required()
                            ->maxLength(120)
                            ->columnSpanFull(),
                        Select::make('status')
                            ->label('Status')
                            ->options(CandidateStatus::class)
                            ->default(CandidateStatus::Aktif)
                            ->required(),
                    ]),
                Section::make('Foto')
                    ->description('JPG/PNG/WebP, maksimal 2 MB. Foto dipotong persegi (1:1) agar semua calon tampil seragam. Wajah di tengah.')
                    ->schema([
                        FileUpload::make('photo_upload')
                            ->label('Unggah foto baru')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize((int) config('voting.photo.max_kilobytes'))
                            ->imageEditor()
                            ->imageEditorAspectRatioOptions(['1:1'])
                            ->imageCropAspectRatio('1:1')
                            ->automaticallyCropImagesToAspectRatio()
                            ->disk('local')
                            ->directory('unggahan-sementara')
                            ->visibility('private')
                            ->helperText('Kosongkan jika tidak ingin mengganti foto.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('number')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('ballot.election'))
            ->columns([
                ImageColumn::make('photo')
                    ->label('Foto')
                    ->circular()
                    ->state(fn (Candidate $record): ?string => $record->photoUrl('thumb'))
                    ->defaultImageUrl(fn (Candidate $record): string => 'data:image/svg+xml;utf8,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80" fill="#dcdbe9"/><text x="40" y="50" font-size="28" text-anchor="middle" fill="#2e2a86" font-family="sans-serif" font-weight="700">'.e($record->initials()).'</text></svg>')),
                TextColumn::make('number')->label('No.')->sortable(),
                TextColumn::make('name')->label('Nama')->searchable()->weight('bold'),
                TextColumn::make('ballot.title')->label('Surat suara'),
                TextColumn::make('unit.name')->label('RT')->placeholder('-'),
                TextColumn::make('ballot.election.name')->label('Pemilihan')->toggleable(),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('photo_key')
                    ->label('Foto ada?')
                    ->state(fn (Candidate $record): string => $record->photo_key ? 'Ada' : 'Belum')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Ada' ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('ballot_id')
                    ->label('Surat suara')
                    ->options(fn (): array => Ballot::query()->with('election')->get()->mapWithKeys(fn (Ballot $ballot): array => [$ballot->id => "{$ballot->election->name} — {$ballot->title}"])->all()),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCandidates::route('/'),
            'create' => CreateCandidate::route('/create'),
            'edit' => EditCandidate::route('/{record}/edit'),
        ];
    }
}
