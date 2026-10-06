<?php

namespace App\Http\Controllers;

use App\Enums\StaffRole;
use App\Models\Election;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Exports\SpreadsheetSanitizer;
use App\Services\Results\ResultSlots;
use App\Services\Voting\ResultsCalculator;
use App\Services\Voting\RoundResolver;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Rekap Excel (bagian 9A): hanya setelah DITUTUP, akses sesuai peran,
 * aman dari formula injection. Tidak memuat siapa memilih siapa.
 */
class RecapExportController extends Controller
{
    public function __invoke(Request $request, Election $election, ResultSlots $slots, ResultsCalculator $calculator): BinaryFileResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($election->status->allowsResults(), 403);

        $allowed = $user->isSuperAdmin()
            || ($election->isDadakan() && $user->hasElectionRole($election, StaffRole::Panitia))
            || (! $election->isDadakan() && $user->isAdminRt());

        abort_unless($allowed && $user->is_active, 403);

        $limitUnit = ! $user->isSuperAdmin() && ! $election->isDadakan() ? $user->unit_id : null;
        $round = $election->currentRound();

        $path = tempnam(sys_get_temp_dir(), 'rekap').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName('Hasil');
        $writer->addRow(Row::fromValues(['Surat suara', 'RT', 'Peringkat', 'Nomor', 'Nama calon', 'Suara', 'Persen', 'Suara sah', 'Suara dibatalkan']));

        foreach ($slots->slots($election) as $slot) {
            if ($limitUnit !== null && $slot['unit'] !== null && $slot['unit']->id !== $limitUnit) {
                continue;
            }

            $slotRound = app(RoundResolver::class)->roundFor($election, $slot['ballot'], $slot['unit']?->id) ?? $round;
            $tally = $calculator->tally($slot['ballot'], $slotRound, $slot['unit']);

            foreach ($tally['candidates'] as $row) {
                $writer->addRow(Row::fromValues(SpreadsheetSanitizer::row([
                    $slot['ballot']->title,
                    $slot['unit']?->name ?? 'Semua',
                    $row['rank'],
                    $row['candidate']->displayNumber(),
                    $row['candidate']->name,
                    $row['votes'],
                    $row['percent'],
                    $tally['valid'],
                    $tally['cancelled'],
                ])));
            }
        }

        $writer->addNewSheetAndMakeItCurrent()->setName('Partisipasi');

        if ($election->isDadakan()) {
            $participation = $calculator->participation($election, $round);
            $writer->addRow(Row::fromValues(['Hadir terdata', 'Memilih', 'Persen', 'Dibantu', 'Datang terlambat', 'Hitung kepala']));
            $writer->addRow(Row::fromValues([$participation['attendees'], $participation['voted'], $participation['percent'], $participation['assisted'], $participation['late'], $election->setting('headcount')]));
        } else {
            $writer->addRow(Row::fromValues(['Surat suara', 'RT', 'Pemilih berhak', 'Memilih', 'Belum', 'Persen', 'Ditambah saat berlangsung']));

            foreach ($election->ballots()->get() as $ballot) {
                foreach (Unit::query()->orderBy('sort')->when($limitUnit !== null, fn ($query) => $query->whereKey($limitUnit))->get() as $unit) {
                    $row = $calculator->ballotParticipation($ballot, app(RoundResolver::class)->roundFor($election, $ballot, $unit->id) ?? $round, $unit->id);

                    if ($row['eligible'] > 0) {
                        $writer->addRow(Row::fromValues(SpreadsheetSanitizer::row([$ballot->title, $unit->name, $row['eligible'], $row['voted'], $row['not_voted'], $row['percent'], $row['added_during_live']])));
                    }
                }
            }
        }

        $writer->close();

        app(AuditLogger::class)->log('recap.exported', $election, $election, meta: ['unit_id' => $limitUnit], actor: $user);

        return response()->download($path, 'rekap-'.$election->public_id.'.xlsx')->deleteFileAfterSend();
    }
}
