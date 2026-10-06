<?php

namespace App\Http\Controllers;

use App\Enums\ElectionStatus;
use App\Enums\OutcomeStatus;
use App\Models\Election;
use App\Models\Unit;
use App\Services\Results\ResultPublication;
use App\Services\Results\ResultSlots;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Halaman publik (tanpa login): hanya hasil resmi yang sudah DIPUBLIKASIKAN.
 * Tidak ada ranking, jumlah suara, atau data pemilih (bagian 9).
 */
class PublicResultController extends Controller
{
    public function index(): Response
    {
        $elections = Election::query()
            ->whereIn('status', [ElectionStatus::Published, ElectionStatus::Unpublished])
            ->latest('closed_at')
            ->get();

        return response()
            ->view('public.index', ['elections' => $elections])
            ->header('Cache-Control', 'public, max-age=60');
    }

    public function show(Request $request, Election $election, ResultSlots $slots, ResultPublication $publication): Response
    {
        abort_unless(in_array($election->status, [ElectionStatus::Published, ElectionStatus::Unpublished], true), 404);

        $rows = collect();
        $units = collect();
        $selectedUnit = null;

        if ($election->status === ElectionStatus::Published) {
            $allSlots = $slots->slots($election);
            $units = $allSlots->pluck('unit')->filter()->unique('id')->sortBy('sort')->values();
            $selectedUnit = $units->firstWhere('code', (string) $request->query('rt')) ?? $units->first();

            $rows = $allSlots
                ->filter(fn (array $slot): bool => $slot['unit'] === null || $slot['unit']->is($selectedUnit))
                ->map(function (array $slot) use ($publication): array {
                    $outcome = $publication->outcome($slot['ballot'], $slot['unit']);

                    return [
                        'title' => $slot['ballot']->title.($slot['unit'] instanceof Unit ? ' '.$slot['unit']->name : ''),
                        'label' => $outcome?->label() ?? 'Belum ditetapkan',
                        'decided' => $outcome?->status === OutcomeStatus::Ditetapkan,
                        'candidates' => $outcome?->candidates ?? collect(),
                    ];
                })
                ->values();
        }

        return response()
            ->view('public.show', [
                'election' => $election,
                'rows' => $rows,
                'units' => $units,
                'selectedUnit' => $selectedUnit,
            ])
            ->header('Cache-Control', 'public, max-age=60');
    }
}
