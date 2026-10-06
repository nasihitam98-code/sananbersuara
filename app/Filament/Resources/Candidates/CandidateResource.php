<?php

namespace App\Filament\Resources\Candidates;

use App\Enums\BallotScope;
use App\Enums\CandidateStatus;
use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Filament\Resources\Candidates\Pages\CreateCandidate;
use App\Filament\Resources\Candidates\Pages\EditCandidate;
use App\Filament\Resources\Candidates\Pages\ListCandidates;
use App\Filament\Support\Workspace;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Unit;
use App\Services\AuditLogger;
use App\Services\CandidatePhotoProcessor;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
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

    public static function shouldRegisterNavigation(): bool
    {
        return Workspace::shows();
    }

    /**
     * Surat suara yang masih boleh diisi kandidat (pemilihan Draf/Siap).
     *
     * @return array<int, string>
     */
    public static function editableBallotOptions(): array
    {
        return Ballot::query()
            ->with('election')
            ->whereHas('election', fn (Builder $query) => $query->whereIn('status', [ElectionStatus::Draft, ElectionStatus::Ready])
                ->when(Workspace::current(), fn (Builder $query, ElectionMode $mode): Builder => $query->where('mode', $mode)))
            ->get()
            ->mapWithKeys(fn (Ballot $ballot): array => [$ballot->id => "{$ballot->election->name} — {$ballot->title}"])
            ->all();
    }

    /**
     * Surat suara bawaan form calon: dari tautan "Kelola calon", atau satu-satunya pilihan yang ada.
     */
    public static function defaultBallotId(): ?int
    {
        $options = static::editableBallotOptions();
        $requested = request()->integer('surat_suara');

        if ($requested && array_key_exists($requested, $options)) {
            return $requested;
        }

        return count($options) === 1 ? (int) array_key_first($options) : null;
    }

    /**
     * Nomor urut berikutnya pada surat suara (untuk surat suara per RT, pilih nomor sendiri per RT).
     */
    public static function nextNumber(mixed $ballotId): ?int
    {
        if (blank($ballotId)) {
            return null;
        }

        return (int) Candidate::query()->where('ballot_id', $ballotId)->max('number') + 1;
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
                            ->default(fn (): ?int => static::defaultBallotId())
                            ->required()
                            ->in(fn (): array => array_keys(static::editableBallotOptions()))
                            ->live()
                            ->afterStateUpdated(fn (mixed $state, Set $set) => $set('number', static::nextNumber($state)))
                            ->visibleOn('create'),
                        Select::make('unit_id')
                            ->label('RT calon')
                            ->options(fn (): array => Unit::query()->orderBy('sort')->pluck('name', 'id')->all())
                            ->helperText('Surat suara per RT: calon hanya tampil untuk pemilih RT ini.')
                            ->visible(fn (Get $get, ?Candidate $record): bool => static::ballotIsPerUnit($record?->ballot_id ?? $get('ballot_id')))
                            ->required(fn (Get $get, ?Candidate $record): bool => static::ballotIsPerUnit($record?->ballot_id ?? $get('ballot_id')))
                            ->disabledOn('edit')
                            ->live(),
                        Select::make('origin_unit_id')
                            ->label('Asal RT (opsional)')
                            ->options(fn (): array => Unit::query()->orderBy('sort')->pluck('name', 'id')->all())
                            ->placeholder('Tidak diisi')
                            ->helperText('Mis. calon RW perwakilan RT 03. Tampil di surat suara dan hasil.')
                            ->visible(fn (Get $get, ?Candidate $record): bool => filled($record?->ballot_id ?? $get('ballot_id')) && ! static::ballotIsPerUnit($record?->ballot_id ?? $get('ballot_id'))),
                        TextInput::make('number')
                            ->label('Nomor urut')
                            ->helperText('Terisi otomatis dengan nomor berikutnya; boleh diubah.')
                            ->default(fn (): ?int => static::nextNumber(static::defaultBallotId()))
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
                            ->rule(fn (Get $get, ?Candidate $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                $ballotId = $record?->ballot_id ?? $get('ballot_id');
                                $unitId = $record?->unit_id ?? ($get('unit_id') ?: null);

                                if (filled($ballotId) && is_string($value) && Candidate::nameTaken((int) $ballotId, $unitId === null ? null : (int) $unitId, $value, $record?->id)) {
                                    $fail('Nama ini sudah ada di surat suara ini. Bila memang orang berbeda, tambahkan keterangan, mis. "(RT 03)".');
                                }
                            })
                            ->maxLength(120)
                            ->columnSpanFull(),
                        Select::make('status')
                            ->label('Status')
                            ->options(CandidateStatus::class)
                            ->default(CandidateStatus::Aktif)
                            ->required()
                            ->visibleOn('edit'),
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with('ballot.election')
                // Calon dari pemilihan yang dibatalkan/diarsipkan tidak ikut ditampilkan.
                ->whereHas('ballot.election', fn (Builder $election): Builder => $election->whereNotIn('status', [ElectionStatus::Cancelled, ElectionStatus::Archived]))
                ->when(Workspace::current(), fn (Builder $query, ElectionMode $mode): Builder => $query->whereHas('ballot.election', fn (Builder $election): Builder => $election->where('mode', $mode))))
            ->columns([
                ImageColumn::make('photo')
                    ->label('Foto')
                    ->circular()
                    ->state(fn (Candidate $record): ?string => $record->photoUrl('thumb'))
                    ->defaultImageUrl(fn (Candidate $record): string => 'data:image/svg+xml;utf8,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80" fill="#dcdbe9"/><text x="40" y="50" font-size="28" text-anchor="middle" fill="#2e2a86" font-family="sans-serif" font-weight="700">'.e($record->initials()).'</text></svg>')),
                // Edit cepat langsung di tabel; dikunci (dan ditolak di server) setelah pemilihan dimulai.
                TextInputColumn::make('number')
                    ->label('No.')
                    ->sortable()
                    ->type('number')
                    ->width('6rem')
                    ->rules(fn (Candidate $record): array => [
                        'required', 'integer', 'min:1', 'max:999',
                        Rule::unique('candidates', 'number')
                            ->where('ballot_id', $record->ballot_id)
                            ->where('unit_id', $record->unit_id)
                            ->ignore($record->id),
                    ])
                    ->validationMessages(['unique' => 'Nomor urut ini sudah dipakai calon lain.'])
                    ->disabled(fn (Candidate $record): bool => ! static::canEdit($record))
                    ->afterStateUpdated(fn (Candidate $record, mixed $state) => static::auditInlineEdit($record, 'number', $state)),
                TextInputColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->rules(fn (Candidate $record): array => [
                        'required', 'string', 'max:120',
                        function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            if (is_string($value) && Candidate::nameTaken($record->ballot_id, $record->unit_id, $value, $record->id)) {
                                $fail('Nama ini sudah ada di surat suara ini.');
                            }
                        },
                    ])
                    ->disabled(fn (Candidate $record): bool => ! static::canEdit($record))
                    ->afterStateUpdated(fn (Candidate $record, mixed $state) => static::auditInlineEdit($record, 'name', $state)),
                TextColumn::make('ballot.title')->label('Surat suara'),
                TextColumn::make('unit.name')->label('RT')->placeholder('-'),
                SelectColumn::make('origin_unit_id')
                    ->label('Asal RT')
                    ->options(fn (): array => Unit::query()->orderBy('sort')->pluck('name', 'id')->all())
                    ->placeholder('-')
                    // Surat suara per RT tidak memakai asal RT.
                    ->disabled(fn (Candidate $record): bool => ! static::canEdit($record) || $record->ballot->scope === BallotScope::PerRt)
                    ->afterStateUpdated(fn (Candidate $record, mixed $state) => static::auditInlineEdit($record, 'origin_unit_id', $state)),
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
                static::deleteAction(),
            ]);
    }

    /**
     * Catat perubahan dari edit cepat di tabel.
     */
    public static function auditInlineEdit(Candidate $record, string $field, mixed $value): void
    {
        app(AuditLogger::class)->log('candidate.updated', $record, $record->ballot->election, meta: [
            'field' => $field,
            'after' => $value,
            'via' => 'tabel',
        ]);
    }

    /**
     * Hapus calon dengan konfirmasi; foto ikut dihapus dan tercatat di audit.
     * Hanya tampil selama pemilihan masih Draf/Siap (lihat CandidatePolicy::delete).
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalHeading(fn (Candidate $record): string => "Hapus calon nomor {$record->displayNumber()} {$record->name}?")
            ->modalDescription('Calon dan fotonya dihapus dari surat suara. Tindakan ini tidak bisa dibatalkan.')
            ->modalSubmitActionLabel('Ya, hapus')
            ->before(fn (Candidate $record) => $record->photo_key !== null ? app(CandidatePhotoProcessor::class)->remove($record) : null)
            ->after(fn (Candidate $record) => app(AuditLogger::class)->log('candidate.deleted', null, $record->ballot->election, meta: $record->only(['number', 'name'])))
            ->successNotificationTitle('Calon dihapus.');
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
