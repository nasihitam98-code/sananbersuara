<?php

namespace App\Filament\Pages\Concerns;

use App\Enums\RestoreReason;
use App\Models\Attendee;
use App\Models\Unit;
use App\Services\AuditLogger;
use App\Services\Exports\SpreadsheetSanitizer;
use App\Services\Voting\ResultsCalculator;
use App\Services\Voting\VoterRightRestorer;
use App\Services\Voting\VotingException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tabel peserta Mode Dadakan di Ruang Kendali (dulu halaman Daftar Hadir terpisah): status sudah/belum, unduh Excel.
 * Tidak pernah memuat pilihan maupun angka PIN; kolom PIN hanya status, dengan tombol "PIN baru" dan "Pulihkan".
 * Kelas pemakai harus memakai InteractsWithElection dan InteractsWithTable.
 */
trait InteractsWithAttendanceTable
{
    protected const PARTICIPATION_COUNT_SQL = '(select count(*) from attendee_participations p where p.attendee_id = attendees.id and p.round_id = ? and p.active_key = 1)';

    /**
     * PIN baru yang baru dibuat; tampil sekali sampai ditekan "Sudah dicatat".
     *
     * @var array{name: string, number: string, pin: string, cancelled: int}|null
     */
    public ?array $reissued = null;

    /**
     * Tab tabel peserta: "belum" (tombol PIN baru), "sudah" (tombol Pulihkan), atau "semua".
     */
    public string $participantTab = 'belum';

    /**
     * Tabel peserta: nomor, nama, RT, jam datang, status sudah/belum, status PIN, dan tombol PIN baru / Pulihkan.
     */
    protected function attendanceTable(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->applyParticipantTab($this->attendanceQuery()))
            ->defaultSort('seq_no', 'desc')
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
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->deferFilters(false)
            ->filters([
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
            ->visible(fn (Attendee $record): bool => $this->canRestore() && ! $this->hasVoted($record))
            ->tooltip('PIN lupa/hilang/terkunci: buat PIN baru. PIN lama tidak berlaku.')
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
            ->visible(fn (Attendee $record): bool => $this->canRestore() && $this->hasVoted($record))
            ->tooltip('Tercatat sudah memilih padahal merasa belum: suara lama dibatalkan, PIN baru dibuat.')
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

    public function setParticipantTab(string $tab): void
    {
        $this->participantTab = in_array($tab, ['belum', 'sudah', 'semua'], true) ? $tab : 'belum';
        $this->resetPage($this->getTablePaginationPageName());
    }

    /**
     * Jumlah per tab untuk label tab.
     *
     * @return array{belum: int, sudah: int, semua: int}
     */
    public function participantTabCounts(): array
    {
        $election = $this->election();

        if ($election === null) {
            return ['belum' => 0, 'sudah' => 0, 'semua' => 0];
        }

        $participation = app(ResultsCalculator::class)->participation($election, $election->currentRound());

        return ['belum' => $participation['not_voted'], 'sudah' => $participation['voted'], 'semua' => $participation['attendees']];
    }

    /**
     * @param  Builder<Attendee>  $query
     * @return Builder<Attendee>
     */
    private function applyParticipantTab(Builder $query): Builder
    {
        return match ($this->participantTab) {
            'sudah' => $query->whereRaw(static::PARTICIPATION_COUNT_SQL.' >= ?', [$this->roundId(), $this->ballotCount()]),
            'semua' => $query,
            default => $query->whereRaw(static::PARTICIPATION_COUNT_SQL.' < ?', [$this->roundId(), $this->ballotCount()]),
        };
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
