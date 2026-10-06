<?php

namespace App\Filament\Pages;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\RestoreReason;
use App\Enums\StaffRole;
use App\Filament\Pages\Concerns\InteractsWithElection;
use App\Filament\Support\Workspace;
use App\Models\Attendee;
use App\Models\Unit;
use App\Services\AuditLogger;
use App\Services\Exports\SpreadsheetSanitizer;
use App\Services\Voting\ResultsCalculator;
use App\Services\Voting\VoterRightRestorer;
use App\Services\Voting\VotingException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Daftar Hadir Mode Dadakan: semua peserta terdata dengan nomor, RT, jam datang, dan status
 * sudah/belum memilih di putaran berjalan. Tidak pernah memuat pilihan maupun angka PIN; kolom PIN hanya
 * menunjukkan statusnya, dengan tombol "PIN baru" (belum memilih) dan "Pulihkan" (sudah memilih).
 */
class AttendanceList extends Page implements HasTable
{
    use InteractsWithElection;
    use InteractsWithTable;

    private const PARTICIPATION_COUNT_SQL = '(select count(*) from attendee_participations p where p.attendee_id = attendees.id and p.round_id = ? and p.active_key = 1)';

    protected string $view = 'filament.pages.attendance-list';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Hari H';

    protected static ?string $navigationLabel = 'Daftar Hadir';

    protected static ?string $title = 'Daftar Hadir';

    protected static ?string $slug = 'daftar-hadir';

    protected static ?int $navigationSort = 3;

    /**
     * PIN baru yang baru dibuat; tampil sekali sampai ditekan "Sudah dicatat".
     *
     * @var array{name: string, number: string, pin: string, cancelled: int}|null
     */
    public ?array $reissued = null;

    public static function shouldRegisterNavigation(): bool
    {
        return Workspace::shows(ElectionMode::Dadakan);
    }

    protected static function allowedStaffRoles(): array
    {
        return [StaffRole::Panitia];
    }

    protected static function allowedStatuses(): array
    {
        return [
            ElectionStatus::Ready,
            ElectionStatus::Berlangsung,
            ElectionStatus::Paused,
            ElectionStatus::Ditutup,
            ElectionStatus::Verifikasi,
            ElectionStatus::Published,
            ElectionStatus::Unpublished,
            // Riwayat: siapa yang sempat hadir tetap bisa dilihat walau pemilihan batal/diarsipkan.
            ElectionStatus::Cancelled,
            ElectionStatus::Archived,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->attendanceQuery())
            ->defaultSort('seq_no', 'desc')
            ->poll('10s')
            ->description(fn (): ?string => $this->summary())
            ->paginationPageOptions([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Belum ada yang didata di Meja Pintu.')
            ->columns([
                TextColumn::make('seq_no')
                    ->label('No.')
                    ->formatStateUsing(fn (mixed $state): string => str_pad((string) $state, 3, '0', STR_PAD_LEFT))
                    ->fontFamily('mono')
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => ctype_digit($search)
                        ? $query->where('seq_no', (int) $search)
                        : $query->whereRaw('0 = 1')),
                TextColumn::make('name')
                    ->label('Nama')
                    ->weight('bold')
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        'name_search',
                        'like',
                        '%'.addcslashes(Attendee::normalizeForSearch($search), '%_\\').'%',
                    )),
                TextColumn::make('unit.name')
                    ->label('RT')
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Jam datang')
                    ->dateTime('H:i')
                    ->sortable(),
                TextColumn::make('voted_ballots')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $this->statusLabel((int) $state))
                    ->color(fn (mixed $state): string => (int) $state >= $this->ballotCount() ? 'success' : ((int) $state > 0 ? 'warning' : 'gray'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('voted_ballots', $direction)),
                TextColumn::make('notes')
                    ->label('Keterangan')
                    ->state(fn (Attendee $record): array => array_values(array_filter([
                        $record->is_late ? 'Datang terlambat' : null,
                        $record->getAttribute('is_assisted') ? 'Dibantu' : null,
                    ])))
                    ->badge()
                    ->color('info')
                    ->placeholder('-'),
                TextColumn::make('pin_status')
                    ->label('PIN')
                    ->state(fn (Attendee $record): string => $this->pinStatus($record))
                    ->badge()
                    ->color(fn (Attendee $record): string => match (true) {
                        $record->isPinLocked() => 'danger',
                        $this->pinWasReplaced($record) => 'warning',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                $this->newPinTableAction(),
                $this->restoreTableAction(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(['belum' => 'Belum memilih', 'sudah' => 'Sudah memilih'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'sudah' => $query->whereRaw(self::PARTICIPATION_COUNT_SQL.' >= ?', [$this->roundId(), $this->ballotCount()]),
                        'belum' => $query->whereRaw(self::PARTICIPATION_COUNT_SQL.' < ?', [$this->roundId(), $this->ballotCount()]),
                        default => $query,
                    }),
                SelectFilter::make('unit_id')
                    ->label('RT')
                    ->options(fn (): array => Unit::query()->orderBy('sort')->pluck('name', 'id')->all()),
                Filter::make('late')
                    ->label('Datang terlambat')
                    ->query(fn (Builder $query): Builder => $query->where('is_late', true)),
                Filter::make('locked')
                    ->label('PIN terkunci')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('pin_locked_at')),
            ])
            ->headerActions([
                Action::make('export')
                    ->label('Unduh Excel')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->action(fn (): StreamedResponse => $this->export()),
            ]);
    }

    /**
     * "PIN baru": untuk peserta yang belum memilih dan lupa/kehilangan PIN atau PIN-nya terkunci.
     * Tidak pernah membatalkan suara (ditolak bila ternyata sudah memilih).
     */
    private function newPinTableAction(): Action
    {
        return Action::make('newPin')
            ->label('PIN baru')
            ->icon(Heroicon::OutlinedKey)
            ->button()
            ->size('sm')
            ->color('primary')
            ->visible(fn (): bool => $this->canRestore())
            ->disabled(fn (Attendee $record): bool => $this->hasVoted($record))
            ->tooltip(fn (Attendee $record): string => $this->hasVoted($record)
                ? 'Sudah memilih. Bila suaranya bukan dia yang memberikan, pakai Pulihkan.'
                : 'PIN lupa/hilang/terkunci: buat PIN baru. PIN lama tidak berlaku.')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedKey)
            ->modalHeading(fn (Attendee $record): string => "Buat PIN baru untuk {$record->name}?")
            ->modalDescription('PIN lama tidak berlaku lagi. PIN baru tampil sekali untuk dicetak atau ditulis.')
            ->modalSubmitActionLabel('Ya, buat PIN baru')
            ->action(function (Attendee $record): void {
                $this->runRestore($record, $record->isPinLocked() ? RestoreReason::PinTerkunci : RestoreReason::PinHilang, null, onlyIfNotVoted: true);
            });
    }

    /**
     * "Pulihkan": untuk peserta yang tercatat sudah memilih padahal merasa belum (mis. nama dipakai orang lain).
     * Suara lamanya DIBATALKAN dan PIN baru dibuat.
     */
    private function restoreTableAction(): Action
    {
        return Action::make('restore')
            ->label('Pulihkan')
            ->icon(Heroicon::OutlinedArrowPath)
            ->button()
            ->size('sm')
            ->color('danger')
            ->outlined()
            ->visible(fn (): bool => $this->canRestore())
            ->disabled(fn (Attendee $record): bool => ! $this->hasVoted($record))
            ->tooltip(fn (Attendee $record): string => $this->hasVoted($record)
                ? 'Tercatat sudah memilih padahal merasa belum: suara lama dibatalkan, PIN baru dibuat.'
                : 'Belum memilih. Bila lupa PIN, cukup tombol PIN baru.')
            ->modalHeading(fn (Attendee $record): string => "Pulihkan hak pilih: {$record->name}")
            ->modalDescription('Suara yang tercatat atas nama orang ini DIBATALKAN (tidak dihapus) tanpa menampilkan pilihannya. PIN lama hangus dan PIN baru dibuat.')
            ->modalSubmitActionLabel('Pulihkan')
            ->schema([
                Select::make('reason')->label('Alasan')->options(RestoreReason::class)->required()->live(),
                Textarea::make('note')->label('Catatan')->maxLength(500)
                    ->required(fn (Get $get): bool => in_array($get('reason'), [RestoreReason::Lainnya, RestoreReason::Lainnya->value], true)),
            ])
            ->action(function (Attendee $record, array $data): void {
                $reason = $data['reason'] instanceof RestoreReason ? $data['reason'] : RestoreReason::from($data['reason']);

                $this->runRestore($record, $reason, $data['note'] ?? null);
            });
    }

    private function runRestore(Attendee $attendee, RestoreReason $reason, ?string $note, bool $onlyIfNotVoted = false): void
    {
        $election = $this->authorizedElection();

        try {
            $result = app(VoterRightRestorer::class)->restore($election, $attendee, $reason, $note, auth()->user(), $onlyIfNotVoted);
        } catch (VotingException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        $this->reissued = [
            'name' => $attendee->name,
            'number' => $attendee->displayNumber(),
            'pin' => $result['pin'],
            'cancelled' => $result['cancelled_votes'],
        ];
    }

    public function acknowledgeReissue(): void
    {
        $this->reissued = null;
    }

    private function canRestore(): bool
    {
        $election = $this->election();

        return $election !== null && $election->status->isLive();
    }

    private function hasVoted(Attendee $attendee): bool
    {
        return (int) $attendee->getAttribute('voted_ballots') > 0;
    }

    private function pinWasReplaced(Attendee $attendee): bool
    {
        return $attendee->pin_issued_at !== null && $attendee->pin_issued_at->gt($attendee->created_at->copy()->addMinute());
    }

    private function pinStatus(Attendee $attendee): string
    {
        return match (true) {
            $attendee->isPinLocked() => 'Terkunci',
            $this->pinWasReplaced($attendee) => 'PIN baru '.$attendee->pin_issued_at->format('H:i'),
            default => 'Diberikan '.($attendee->pin_issued_at ?? $attendee->created_at)?->format('H:i'),
        };
    }

    /**
     * Peserta pemilihan terpilih + jumlah surat suara yang sudah dipilih di putaran berjalan.
     *
     * @return Builder<Attendee>
     */
    private function attendanceQuery(): Builder
    {
        $election = $this->election();
        $participations = fn () => DB::query()
            ->from('attendee_participations', 'p')
            ->whereColumn('p.attendee_id', 'attendees.id')
            ->where('p.round_id', $this->roundId())
            ->where('p.active_key', 1);

        return Attendee::query()
            ->where('election_id', $election?->id ?? 0)
            ->with('unit')
            ->select('attendees.*')
            ->selectSub($participations()->selectRaw('count(*)'), 'voted_ballots')
            ->selectSub($participations()->selectRaw('coalesce(max(p.is_assisted), 0)'), 'is_assisted');
    }

    private function roundId(): int
    {
        return $this->election()?->currentRound()?->id ?? 0;
    }

    private function ballotCount(): int
    {
        return max(1, $this->election()?->ballots()->count() ?? 1);
    }

    private function statusLabel(int $votedBallots): string
    {
        return match (true) {
            $votedBallots >= $this->ballotCount() => '✓ Sudah memilih',
            $votedBallots > 0 => 'Sebagian',
            default => 'Belum memilih',
        };
    }

    private function summary(): ?string
    {
        $election = $this->election();

        if ($election === null) {
            return null;
        }

        $participation = app(ResultsCalculator::class)->participation($election, $election->currentRound());

        return "Hadir terdata {$participation['attendees']} · sudah memilih {$participation['voted']} · belum {$participation['not_voted']}";
    }

    private function export(): StreamedResponse
    {
        $election = $this->authorizedElection();
        $rows = $this->attendanceQuery()->reorder('seq_no')->get();

        $path = tempnam(sys_get_temp_dir(), 'hadir');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Daftar Hadir');
        $writer->addRow(Row::fromValues(['No. hadir', 'Nama', 'RT', 'Jam datang', 'Status', 'Datang terlambat', 'Dibantu', 'PIN terkunci']));

        foreach ($rows as $attendee) {
            $writer->addRow(Row::fromValues(SpreadsheetSanitizer::row([
                $attendee->displayNumber(),
                $attendee->name,
                $attendee->unit?->name ?? '-',
                $attendee->created_at?->format('d/m/Y H:i'),
                $this->statusLabel((int) $attendee->getAttribute('voted_ballots')),
                $attendee->is_late ? 'Ya' : '',
                $attendee->getAttribute('is_assisted') ? 'Ya' : '',
                $attendee->pin_locked_at !== null ? 'Ya' : '',
            ])));
        }

        $writer->close();
        $content = (string) file_get_contents($path);
        @unlink($path);

        app(AuditLogger::class)->log('attendance.exported', $election, $election, meta: ['rows' => $rows->count()]);

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, 'daftar-hadir-'.$election->public_id.'.xlsx');
    }
}
