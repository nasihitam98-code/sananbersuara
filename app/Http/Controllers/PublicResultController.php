<?php

namespace App\Http\Controllers;

use App\Enums\CandidateStatus;
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

        $upcoming = Election::query()
            ->whereIn('status', [ElectionStatus::Ready, ElectionStatus::Berlangsung, ElectionStatus::Paused])
            ->latest()
            ->get(['id', 'public_id', 'name', 'status']);

        return response()
            ->view('public.index', ['elections' => $elections, 'upcoming' => $upcoming])
            ->header('Cache-Control', 'public, max-age=60');
    }

    /**
     * "Kenali calon": nama, nomor, asal RT, visi & misi. Untuk pemilihan yang siap/berjalan/sudah selesai;
     * tidak memuat angka suara.
     */
    public function candidates(Election $election): Response
    {
        abort_if(in_array($election->status, [ElectionStatus::Draft, ElectionStatus::Cancelled, ElectionStatus::Archived], true), 404);

        $ballots = $election->ballots()
            ->orderBy('sort')
            ->get()
            ->map(fn ($ballot): array => [
                'title' => $ballot->title,
                'candidates' => $ballot->ballotCandidates()
                    ->with(['unit', 'originUnit'])
                    ->where('status', CandidateStatus::Aktif)
                    ->orderBy('unit_id')
                    ->orderBy('number')
                    ->get(),
            ]);

        return response()
            ->view('public.candidates', ['election' => $election, 'ballots' => $ballots])
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
