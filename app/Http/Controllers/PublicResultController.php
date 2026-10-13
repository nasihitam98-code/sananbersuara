<?php

namespace App\Http\Controllers;

use App\Enums\ElectionStatus;
use App\Enums\OutcomeStatus;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Unit;
use App\Services\Results\PublicTurnout;
use App\Services\Results\ResultPublication;
use App\Services\Results\ResultSlots;
use App\Services\Voting\ResultsCalculator;
use App\Services\Voting\RoundResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * Portal publik (tanpa login) untuk pengumuman akhir: hasil resmi yang sudah DIPUBLIKASIKAN, plus penanda
 * pemilihan yang sedang berjalan. Profil calon tidak ditampilkan di sini (visi & misi ada di surat suara pemilih).
 * Setelah diumumkan, yang ditampilkan: nama yang ditetapkan panitia, perolehan suara resmi tiap calon (K21 diubah
 * pemilik 2026-10-12), dan partisipasi warga. Data pemilih tidak pernah ditampilkan.
 */
class PublicResultController extends Controller
{
    /** @var array<int, ElectionStatus> */
    private const RUNNING = [ElectionStatus::Ready, ElectionStatus::Berlangsung, ElectionStatus::Paused];

    /** @var array<int, ElectionStatus> */
    private const ANNOUNCED = [ElectionStatus::Published, ElectionStatus::Unpublished];

    public function __construct(
        private ResultSlots $slots,
        private ResultPublication $publication,
        private PublicTurnout $turnout,
        private ResultsCalculator $calculator,
        private RoundResolver $rounds,
    ) {}

    /**
     * Portal satu halaman: menu di navbar meluncur ke bagian Hasil, Calon, Pemilihan, dan Cara memilih.
     */
    public function index(): Response
    {
        $running = $this->running();
        $announced = $this->announced();
        $featured = $announced->firstWhere('status', ElectionStatus::Published);
        $cards = $featured === null ? collect() : $this->resultCards($featured);
        $turnout = $featured === null ? null : $this->turnout->for($featured);

        return $this->publicView('public.index', [
            'running' => $running,
            'announced' => $announced,
            'featured' => $featured,
            'cards' => $cards,
            'unitCards' => $cards->filter(fn (array $card): bool => $card['unit'] !== null)->values(),
            'turnout' => $turnout,
            'unitTurnout' => collect($turnout['units'] ?? [])->keyBy('unit'),
            'history' => $this->turnout->history(),
        ]);
    }

    /** Alamat lama menu (/pemilihan, /cara-memilih, /hasil) diarahkan ke bagiannya di beranda. */
    public function section(string $section): RedirectResponse
    {
        return redirect()->to(route('public.index').'#'.$section);
    }

    public function show(Request $request, Election $election): Response
    {
        abort_unless(in_array($election->status, self::ANNOUNCED, true) && $election->isPublic(), 404);

        $cards = collect();
        $units = collect();
        $selectedUnit = null;

        if ($election->status === ElectionStatus::Published) {
            $allCards = $this->resultCards($election);
            $units = $allCards->pluck('unit')->filter()->unique('id')->sortBy('sort')->values();
            $selectedUnit = $units->firstWhere('code', (string) $request->query('rt')) ?? $units->first();
            $cards = $allCards->filter(fn (array $card): bool => $card['unit'] === null || $card['unit']->is($selectedUnit))->values();
        }

        return $this->publicView('public.show', [
            'election' => $election,
            'cards' => $cards,
            'units' => $units,
            'selectedUnit' => $selectedUnit,
        ]);
    }

    /**
     * Satu kartu per slot hasil (surat suara, atau satu RT pada surat suara per RT): siapa yang ditetapkan panitia
     * dan perolehan suara resmi tiap calon (putaran yang sama dengan Meja Verifikasi dan berita acara).
     * Hanya dipanggil untuk pemilihan yang sudah DIPUBLIKASIKAN.
     *
     * @return Collection<int, array{title: string, office: string, unit: ?Unit, label: string, decided: bool, candidates: Collection<int, Candidate>, tally: array{valid: int, rows: array<int, array{candidate: Candidate, votes: int, percent: float, rank: int, elected: bool}>}}>
     */
    private function resultCards(Election $election): Collection
    {
        $fallbackRound = $election->currentRound();

        return $this->slots->slots($election)
            ->map(function (array $slot) use ($election, $fallbackRound): array {
                $outcome = $this->publication->outcome($slot['ballot'], $slot['unit']);
                $decided = $outcome?->status === OutcomeStatus::Ditetapkan;
                $electedIds = $decided ? $outcome->candidates->modelKeys() : [];
                $round = $this->rounds->roundFor($election, $slot['ballot'], $slot['unit']?->id) ?? $fallbackRound;
                $tally = $round === null ? ['valid' => 0, 'candidates' => []] : $this->calculator->tally($slot['ballot'], $round, $slot['unit']);

                return [
                    'title' => $slot['ballot']->title.($slot['unit'] instanceof Unit ? ' '.$slot['unit']->name : ''),
                    'office' => $slot['ballot']->title,
                    'unit' => $slot['unit'],
                    'label' => $outcome?->label() ?? 'Belum ditetapkan',
                    'decided' => $decided,
                    'candidates' => $outcome?->candidates ?? collect(),
                    'tally' => [
                        'valid' => $tally['valid'],
                        'rows' => collect($tally['candidates'])
                            ->map(fn (array $row): array => $row + ['elected' => in_array($row['candidate']->id, $electedIds, true)])
                            ->all(),
                    ],
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int, Election>
     */
    private function running(): Collection
    {
        return Election::query()->whereIn('status', self::RUNNING)->latest()->get()->filter->isPublic()->values();
    }

    /**
     * @return Collection<int, Election>
     */
    private function announced(): Collection
    {
        return Election::query()->whereIn('status', self::ANNOUNCED)->latest('closed_at')->get()->filter->isPublic()->values();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function publicView(string $view, array $data = []): Response
    {
        return response()
            ->view($view, $data + [
                'headline' => $this->headline(),
                // Bagian/menu "Pemilihan" hanya berguna bila ada lebih dari satu pemilihan.
                'showElections' => $this->running()->count() + $this->announced()->count() > 1,
            ])
            ->header('Cache-Control', 'public, max-age=60');
    }

    /**
     * Penanda di kanan atas header (seperti "투표율" di referensi): pemilihan yang sedang berjalan,
     * atau hasil resmi terakhir.
     *
     * @return array{tone: string, label: string, text: string, url: string}|null
     */
    private function headline(): ?array
    {
        $running = $this->running()->first();

        if ($running !== null) {
            return [
                'tone' => $running->status->isLive() ? 'live' : 'soon',
                'label' => $running->status->isLive() ? 'Sedang berlangsung' : 'Segera dimulai',
                'text' => $running->name,
                'url' => route('public.index'),
            ];
        }

        $published = $this->announced()->firstWhere('status', ElectionStatus::Published);

        return $published === null ? null : [
            'tone' => 'done',
            'label' => 'Hasil resmi',
            'text' => $published->name,
            'url' => route('public.show', $published->public_id),
        ];
    }
}
