<?php

namespace App\Filament\Pages;

use App\Enums\ElectionStatus;
use App\Enums\StaffRole;
use App\Filament\Pages\Concerns\InteractsWithElection;
use App\Models\Attendee;
use App\Models\Unit;
use App\Services\AuditLogger;
use App\Services\Exports\SpreadsheetSanitizer;
use App\Services\Voting\ResultsCalculator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
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
 * sudah/belum memilih di putaran berjalan. Tidak pernah memuat pilihan maupun PIN.
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
                        $record->pin_locked_at !== null ? 'PIN terkunci' : null,
                    ])))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'PIN terkunci' ? 'danger' : 'info')
                    ->placeholder('-'),
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
