<?php

namespace App\Http\Controllers;

use App\Enums\StaffRole;
use App\Models\OfficialReport;
use App\Models\User;
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
}
