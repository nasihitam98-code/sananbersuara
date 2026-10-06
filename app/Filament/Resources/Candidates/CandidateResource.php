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
use App\Models\GalleryPhoto;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CandidatePhotoProcessor;
use App\Services\Candidates\CandidateGallery;
use App\Services\Voting\VotingException;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
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

    /**
     * Calon yang fotonya masih boleh diubah (pemilihan Draf/Siap, sesuai mode kerja), untuk dropdown.
     *
     * @return array<int, string>
     */
    public static function editableCandidateOptions(): array
    {
        return Candidate::query()
            ->with(['ballot.election', 'unit'])
            ->whereHas('ballot.election', fn (Builder $query) => $query->whereIn('status', [ElectionStatus::Draft, ElectionStatus::Ready])
                ->when(Workspace::current(), fn (Builder $query, ElectionMode $mode): Builder => $query->where('mode', $mode)))
            ->orderBy('ballot_id')
            ->orderBy('unit_id')
            ->orderBy('number')
            ->get()
            ->mapWithKeys(fn (Candidate $candidate): array => [
                $candidate->id => "{$candidate->ballot->title}".($candidate->unit ? " {$candidate->unit->name}" : '')." — {$candidate->displayNumber()} {$candidate->name}",
            ])
            ->all();
    }

    /**
     * Pemilih foto galeri berbentuk grid gambar (klik untuk memilih), dengan pencarian nama file.
     */
    public static function galleryPickerField(string $name, string $label): ViewField
    {
        return ViewField::make($name)
            ->label($label)
            ->view('filament.forms.gallery-picker')
            ->viewData(fn (): array => ['photos' => GalleryPhoto::query()->with('candidates')->latest()->limit(300)->get()])
            ->rule('exists:gallery_photos,id');
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

                                $same = filled($ballotId) && is_string($value)
                                    ? Candidate::sameNameAs((int) $ballotId, $unitId === null ? null : (int) $unitId, $value, $record?->id)
                                    : null;

                                if ($same !== null) {
                                    $fail("Nama ini sama/mirip dengan \"{$same->name}\" (nomor {$same->displayNumber()}). Bila memang orang berbeda, tambahkan keterangan, mis. \"(RT 03)\".");
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
                    ->description('JPG/PNG/WebP, maksimal '.intdiv((int) config('voting.photo.max_kilobytes'), 1024).' MB (foto langsung dari HP boleh). Foto dipotong persegi (1:1) agar semua calon tampil seragam. Wajah di tengah.')
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
                        static::galleryPickerField('gallery_photo_pick', 'Atau pilih dari Galeri Foto')
                            ->helperText('Klik salah satu foto. Dipakai bila tidak mengunggah foto baru di atas.'),
                    ]),
            ]);
    }

    /**
     * Pilih foto dari Galeri Foto langsung dari baris daftar calon.
     */
    public static function galleryPhotoAction(): Action
    {
        return Action::make('galleryPhoto')
            ->label('Foto')
            ->icon(Heroicon::OutlinedPhoto)
            ->color('gray')
            ->visible(fn (Candidate $record): bool => static::canEdit($record))
            ->modalHeading(fn (Candidate $record): string => "Pilih foto untuk {$record->displayNumber()} {$record->name}")
            ->modalSubmitActionLabel('Pasang')
            ->modalWidth(Width::SevenExtraLarge)
            ->schema([
                static::galleryPickerField('gallery_photo_id', 'Klik foto yang akan dipasang')->required(),
            ])
            ->action(function (Candidate $record, array $data): void {
                /** @var User $user */
                $user = auth()->user();

                try {
                    app(CandidateGallery::class)->assign($record, GalleryPhoto::query()->findOrFail($data['gallery_photo_id']), $user);
                } catch (VotingException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title("Foto dipasang ke {$record->displayNumber()} {$record->name}.")->success()->send();
            });
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
                    ->extraInputAttributes(['style' => 'width: 4.5rem'])
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
                    ->extraInputAttributes(['style' => 'min-width: 13rem'])
                    ->rules(fn (Candidate $record): array => [
                        'required', 'string', 'max:120',
                        function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            $same = is_string($value) ? Candidate::sameNameAs($record->ballot_id, $record->unit_id, $value, $record->id) : null;

                            if ($same !== null) {
                                $fail("Sama/mirip dengan \"{$same->name}\" (nomor {$same->displayNumber()}).");
                            }
                        },
                    ])
                    ->disabled(fn (Candidate $record): bool => ! static::canEdit($record))
                    ->afterStateUpdated(fn (Candidate $record, mixed $state) => static::auditInlineEdit($record, 'name', $state)),
                TextColumn::make('ballot.title')->label('Surat suara'),
                // RT calon hanya bermakna untuk surat suara per RT (Mode Resmi).
                TextColumn::make('unit.name')->label('RT')->placeholder('-')
                    ->visible(fn (): bool => Workspace::current() !== ElectionMode::Dadakan),
                SelectColumn::make('origin_unit_id')
                    ->label('Asal RT')
                    ->options(fn (): array => Unit::query()->orderBy('sort')->pluck('name', 'id')->all())
                    ->placeholder('-')
                    ->extraInputAttributes(['style' => 'width: 7.5rem'])
                    // Surat suara per RT tidak memakai asal RT.
                    ->disabled(fn (Candidate $record): bool => ! static::canEdit($record) || $record->ballot->scope === BallotScope::PerRt)
                    ->afterStateUpdated(fn (Candidate $record, mixed $state) => static::auditInlineEdit($record, 'origin_unit_id', $state)),
                TextColumn::make('ballot.election.name')->label('Pemilihan')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Status')->badge(),
            ])
            ->filters([
                Filter::make('without_photo')
                    ->label('Belum ada foto')
                    ->query(fn (Builder $query): Builder => $query->whereNull('photo_key')),
                SelectFilter::make('ballot_id')
                    ->label('Surat suara')
                    ->options(fn (): array => Ballot::query()->with('election')->get()->mapWithKeys(fn (Ballot $ballot): array => [$ballot->id => "{$ballot->election->name} — {$ballot->title}"])->all()),
            ])
            // Tombol ikon agar tabel tidak perlu digeser; keterangan muncul saat kursor diarahkan.
            ->recordActions([
                static::galleryPhotoAction()->iconButton()->tooltip('Pilih foto dari galeri'),
                EditAction::make()->iconButton()->tooltip('Ubah (ganti/hapus foto, dll.)'),
                static::deleteAction()->iconButton()->tooltip('Hapus calon'),
            ]);
    }

    /**
     * Setelah edit cepat di tabel tersimpan: catat di audit dan beri tanda "Tersimpan".
     */
    public static function auditInlineEdit(Candidate $record, string $field, mixed $value): void
    {
        app(AuditLogger::class)->log('candidate.updated', $record, $record->ballot->election, meta: [
            'field' => $field,
            'after' => $value,
            'via' => 'tabel',
        ]);

        $label = ['number' => 'Nomor', 'name' => 'Nama', 'origin_unit_id' => 'Asal RT'][$field] ?? $field;

        Notification::make()
            ->title("Tersimpan: {$label} calon nomor {$record->displayNumber()}.")
            ->success()
            ->duration(2500)
            ->send();
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
