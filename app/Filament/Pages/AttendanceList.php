<?php

namespace App\Filament\Pages;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\StaffRole;
use App\Filament\Pages\Concerns\InteractsWithAttendanceTable;
use App\Filament\Pages\Concerns\InteractsWithElection;
use App\Filament\Support\Workspace;
use App\Services\AuditLogger;
use App\Services\Exports\SpreadsheetSanitizer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
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
    use InteractsWithAttendanceTable;
    use InteractsWithElection;
    use InteractsWithTable;

    protected string $view = 'filament.pages.attendance-list';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Hari H';

    protected static ?string $navigationLabel = 'Daftar Hadir';

    protected static ?string $title = 'Daftar Hadir';

    protected static ?string $slug = 'daftar-hadir';

    protected static ?int $navigationSort = 3;

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
        return $this->attendanceTable($table)
            ->headerActions([
                Action::make('export')
                    ->label('Unduh Excel')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->action(fn (): StreamedResponse => $this->export()),
            ]);
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
