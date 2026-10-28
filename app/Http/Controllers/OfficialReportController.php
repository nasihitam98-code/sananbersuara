<?php

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use App\Enums\StaffRole;
use App\Models\Election;
use App\Models\OfficialReport;
use App\Models\Unit;
use App\Models\User;
use App\Services\Results\OfficialReportService;
use App\Services\Results\ResultSlots;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tampilan cetak berita acara (Cetak / Simpan sebagai PDF dari browser).
 */
class OfficialReportController extends Controller
{
    public function show(Request $request, OfficialReport $report): View
    {
        /** @var User $user */
        $user = $request->user();

        $isOwnRtReport = ! $report->election->isDadakan() && $user->isAdminRt() && $report->unit_id === $user->unit_id;

        abort_unless($user->is_active && ($user->hasElectionRole($report->election, StaffRole::Panitia) || $isOwnRtReport), 403);

        return view('reports.show', [
            'report' => $report->load(['election', 'unit', 'creator', 'ratifier']),
            'data' => $report->data(),
            'checksumValid' => $report->checksumIsValid(),
        ]);
    }

    /**
     * Semua berita acara terbaru satu pemilihan (Keseluruhan lalu tiap RT) dalam satu halaman cetak, untuk panitia.
     */
    public function all(Request $request, Election $election, OfficialReportService $reports, ResultSlots $slots): View
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->is_active && $user->hasElectionRole($election, StaffRole::Panitia), 403);

        $current = $slots->reportScopes($election)
            ->map(fn (?Unit $scope): ?OfficialReport => $reports->current($election, $scope))
            ->filter()
            ->values();

        return view('reports.all', [
            'election' => $election,
            'sheets' => $current->map(fn (OfficialReport $report): array => [
                'report' => $report->load(['election', 'unit', 'creator', 'ratifier']),
                'data' => $report->data(),
                'checksumValid' => $report->checksumIsValid(),
            ])->all(),
            'drafts' => $current->filter(fn (OfficialReport $report): bool => $report->status === ReportStatus::Draft)->count(),
        ]);
    }
}
