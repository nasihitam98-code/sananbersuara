<?php

namespace App\Filament\Pages\Concerns;

use App\Enums\ElectionStatus;
use App\Enums\RestoreReason;
use App\Enums\StaffRole;
use App\Models\Attendee;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Exports\SpreadsheetSanitizer;
use App\Services\Voting\AttendeeRegistrar;
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
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tabel peserta Mode Dadakan, sama di Ruang Kendali dan Meja Pintu: status sudah/belum, PIN (keputusan panitia
 * 16 Okt 2026; tersimpan terenkripsi sampai hasil diumumkan), Cetak ulang, PIN baru dan Pulihkan (khusus Panitia),
 * unduh Excel (tanpa PIN). Tidak pernah memuat pilihan.
 * Kelas pemakai harus memakai InteractsWithElection dan InteractsWithTable.
 */
trait InteractsWithAttendanceTable
{
    protected const PARTICIPATION_COUNT_SQL = '(select count(*) from attendee_participations p where p.attendee_id = attendees.id and p.round_id = ? and p.active_key = 1)';

    /**
     * PIN baru yang baru dibuat; kartunya tampil sampai ditekan "Sudah diberikan".
     *
     * @var array{name: string, number: string, pin: string, cancelled: int}|null
     */
    public ?array $reissued = null;

    /**
     * Tab tabel peserta: "belum" (tombol PIN baru), "sudah" (tombol Pulihkan), atau "semua".
     */
    public string $participantTab = 'belum';

    /**
     * Struk yang sedang dicetak ulang dari tabel; token baru tiap cetak agar elemen cetak dibuat ulang dan langsung mencetak.
     *
     * @var array{name: string, number: string, pin: string, note: ?string, token: string}|null
     */
    public ?array $printCard = null;

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
                    ))
                    // Jam datang dan keterangan di bawah nama (bukan kolom sendiri) agar tombol Cetak ulang tidak terpotong.
                    ->description(fn (Attendee $record): string => implode(' · ', array_filter([
                        'Datang '.$record->created_at?->format('H:i'),
                        $record->is_late ? 'Datang terlambat' : null,
                        $record->getAttribute('is_assisted') ? 'Dibantu' : null,
                    ]))),
                TextColumn::make('unit.name')
                    ->label('RT')
                    ->placeholder('-'),
                TextColumn::make('voted_ballots')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $this->statusLabel((int) $state))
                    ->color(fn (mixed $state): string => (int) $state >= $this->ballotCount() ? 'success' : ((int) $state > 0 ? 'warning' : 'gray'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('voted_ballots', $direction)),
                TextColumn::make('pin')
                    ->label('PIN')
                    ->state(fn (Attendee $record): ?string => $record->storedPin())
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->size('lg')
                    ->placeholder('—')
                    // Status PIN di bawah angkanya (satu kolom), agar tabel tidak melebar keluar layar.
                    ->description(fn (Attendee $record): string => $this->pinStatus($record))
                    ->color(fn (Attendee $record): ?string => $record->isPinLocked() ? 'danger' : null)
                    ->tooltip(fn (Attendee $record): ?string => $record->storedPin() === null ? 'PIN tidak tersimpan (didata sebelum PIN disimpan, atau hasil sudah diumumkan). Cetak ulang membuat PIN baru.' : null),
            ])
            ->recordActions([
                $this->reprintTableAction(),
                $this->deleteTableAction(),
                $this->newPinTableAction(),
                $this->restoreTableAction(),
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
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
     * "Cetak ulang": struk dengan PIN yang sama (kertas habis, struk rusak/hilang). Bila PIN tidak tersimpan, PIN baru
     * dibuat; jalur itu hanya untuk Panitia/Super Admin atau petugas yang mendaftarkan orang itu.
     */
    private function reprintTableAction(): Action
    {
        return Action::make('reprint')
            ->label('Cetak ulang')
            ->icon(Heroicon::OutlinedPrinter)
            ->button()
            ->size('sm')
            ->color('gray')
            ->outlined()
            ->visible(fn (Attendee $record): bool => $this->canReprint($record))
            // Selalu dikonfirmasi (syarat per baris tidak bisa: Filament menyimpan hasil requiresConfirmation pertama).
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedPrinter)
            ->modalHeading(fn (Attendee $record): string => "Cetak ulang struk {$record->name}?")
            ->modalDescription(fn (Attendee $record): string => $record->storedPin() !== null
                ? "PIN tetap sama ({$record->storedPin()})."
                : 'PIN orang ini tidak tersimpan, jadi struk baru berisi PIN BARU dan PIN lama tidak berlaku lagi.')
            ->modalSubmitActionLabel(fn (Attendee $record): string => $record->storedPin() !== null ? 'Cetak' : 'Ya, cetak dengan PIN baru')
            ->action(function (Attendee $record): void {
                /** @var User $user */
                $user = auth()->user();

                try {
                    $result = app(AttendeeRegistrar::class)->reprint($this->authorizedElection(), $record, $user);
                } catch (VotingException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                $this->printCard = [
                    'name' => $record->name,
                    'number' => $record->displayNumber(),
                    'pin' => $result['pin'],
                    'note' => $result['is_new'] ? 'PIN BARU. PIN lama tidak berlaku.' : null,
                    'token' => Str::random(12),
                ];
            });
    }

    /**
     * "Hapus" dari daftar hadir (salah ketik, terdaftar dua kali): hanya yang belum memilih, dengan konfirmasi dan alasan.
     * Panitia/Super Admin untuk siapa saja; Petugas Pintu hanya untuk orang yang ia daftarkan sendiri.
     */
    private function deleteTableAction(): Action
    {
        return Action::make('deleteAttendee')
            ->label('Hapus')
            ->iconButton()
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->tooltip('Hapus dari daftar hadir')
            ->visible(fn (Attendee $record): bool => $this->canDeleteAttendee($record))
            ->requiresConfirmation()
            ->modalHeading(fn (Attendee $record): string => "Hapus {$record->name} (No. {$record->displayNumber()}) dari daftar hadir?")
            ->modalDescription('PIN orang ini langsung tidak berlaku. Nomor hadirnya tidak dipakai ulang. Tindakan ini tidak bisa dibatalkan dan tercatat di Audit Log.')
            ->modalSubmitActionLabel('Ya, hapus')
            ->schema([
                Textarea::make('reason')
                    ->label('Alasan')
                    ->placeholder('Mis. salah ketik nama, terdaftar dua kali, bukan warga')
                    ->required()
                    ->minLength(3)
                    ->maxLength(300),
            ])
            ->action(function (Attendee $record, array $data): void {
                /** @var User $user */
                $user = auth()->user();
                abort_unless($this->canDeleteAttendee($record), 403);

                try {
                    app(AttendeeRegistrar::class)->delete($this->authorizedElection(), $record, $user, $data['reason']);
                } catch (VotingException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title("{$record->name} dihapus dari daftar hadir.")->success()->send();
            });
    }

    private function canDeleteAttendee(Attendee $attendee): bool
    {
        $election = $this->election();
        $user = auth()->user();

        if ($election === null || ! $user instanceof User || $this->hasVoted($attendee)
            || ! in_array($election->status, [ElectionStatus::Ready, ElectionStatus::Berlangsung, ElectionStatus::Paused], true)) {
            return false;
        }

        return $this->isCommittee() || $attendee->created_by === $user->id;
    }

    public function closePrintCard(): void
    {
        $this->printCard = null;
    }

    /**
     * Belum memilih. PIN tersimpan: siapa pun yang bisa membuka halaman ini. PIN tidak tersimpan (jadi PIN baru):
     * Panitia/Super Admin, atau petugas yang mendaftarkan orang itu sendiri.
     */
    private function canReprint(Attendee $attendee): bool
    {
        $election = $this->election();

        if ($election === null || ! in_array($election->status, [ElectionStatus::Ready, ElectionStatus::Berlangsung, ElectionStatus::Paused], true) || $this->hasVoted($attendee)) {
            return false;
        }

        /** @var User $user */
        $user = auth()->user();

        return $attendee->storedPin() !== null
            || $this->isCommittee()
            || $attendee->created_by === $user->id;
    }

    /**
     * Super Admin atau Panitia pemilihan ini (Petugas Pintu tidak).
     */
    private function isCommittee(): bool
    {
        $election = $this->election();
        $user = auth()->user();

        return $election !== null && $user instanceof User && $user->hasElectionRole($election, StaffRole::Panitia);
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
            ->color('gray')
            ->outlined()
            ->visible(fn (Attendee $record): bool => $this->canRestore() && ! $this->hasVoted($record))
            ->tooltip('PIN lupa/hilang/terkunci: buat PIN baru. PIN lama tidak berlaku.')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedKey)
            ->modalHeading(fn (Attendee $record): string => "Buat PIN baru untuk {$record->name}?")
            ->modalDescription('PIN lama tidak berlaku lagi. PIN baru langsung bisa dicetak dan tampil di tabel.')
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
        abort_unless($this->canRestore(), 403);

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

    /**
     * PIN baru dan Pulihkan: hanya saat berlangsung, dan hanya Panitia/Super Admin (juga bila tabel tampil di Meja Pintu).
     */
    private function canRestore(): bool
    {
        $election = $this->election();

        return $election !== null && $election->status->isLive() && $this->isCommittee();
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
