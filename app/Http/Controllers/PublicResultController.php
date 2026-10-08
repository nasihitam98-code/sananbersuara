<?php

namespace App\Http\Controllers;

use App\Enums\CandidateStatus;
use App\Enums\ElectionStatus;
use App\Enums\OutcomeStatus;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Unit;
use App\Services\Results\PublicTurnout;
use App\Services\Results\ResultPublication;
use App\Services\Results\ResultSlots;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * Portal publik (tanpa login): pemilihan yang berjalan, profil calon, dan hasil resmi yang sudah
 * DIPUBLIKASIKAN. Tidak ada ranking, jumlah suara calon, atau data pemilih (bagian 9, K21); yang ditampilkan\n * hanya nama yang ditetapkan panitia dan partisipasi warga.
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
        $focus = $running->first() ?? $featured ?? $announced->first();

        return $this->publicView('public.index', [
            'running' => $running,
            'announced' => $announced,
            'featured' => $featured,
            'cards' => $cards,
            'unitCards' => $cards->filter(fn (array $card): bool => $card['unit'] !== null)->values(),
            'turnout' => $featured === null ? null : $this->turnout->for($featured),
            'history' => $this->turnout->history(),
            'focus' => $focus,
            'ballots' => $focus === null ? collect() : $this->candidateBallots($focus),
        ]);
    }

    /** Alamat lama menu (/pemilihan, /cara-memilih, /hasil, /calon) diarahkan ke bagiannya di beranda. */
    public function section(string $section): RedirectResponse
    {
        return redirect()->to(route('public.index').'#'.$section);
    }

    /**
     * "Kenali calon": nama, nomor, asal RT, visi & misi. Untuk pemilihan yang siap/berjalan/sudah selesai;
     * tidak memuat angka suara.
     */
    public function candidates(Election $election): Response
    {
        abort_if(in_array($election->status, [ElectionStatus::Draft, ElectionStatus::Cancelled, ElectionStatus::Archived], true), 404);

        return $this->publicView('public.candidates', ['election' => $election, 'ballots' => $this->candidateBallots($election)]);
    }

    /**
     * @return Collection<int, array{title: string, candidates: Collection<int, Candidate>}>
     */
    private function candidateBallots(Election $election): Collection
    {
        return $election->ballots()
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
    }

    public function show(Request $request, Election $election): Response
    {
        abort_unless(in_array($election->status, self::ANNOUNCED, true), 404);

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
     * Satu kartu per slot hasil (surat suara, atau satu RT pada surat suara per RT): siapa yang ditetapkan panitia.
     *
     * @return Collection<int, array{title: string, office: string, unit: ?Unit, label: string, decided: bool, candidates: Collection<int, Candidate>}>
     */
    private function resultCards(Election $election): Collection
    {
        return $this->slots->slots($election)
            ->map(function (array $slot): array {
                $outcome = $this->publication->outcome($slot['ballot'], $slot['unit']);

                return [
                    'title' => $slot['ballot']->title.($slot['unit'] instanceof Unit ? ' '.$slot['unit']->name : ''),
                    'office' => $slot['ballot']->title,
                    'unit' => $slot['unit'],
                    'label' => $outcome?->label() ?? 'Belum ditetapkan',
                    'decided' => $outcome?->status === OutcomeStatus::Ditetapkan,
                    'candidates' => $outcome?->candidates ?? collect(),
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int, Election>
     */
    private function running(): Collection
    {
        return Election::query()->whereIn('status', self::RUNNING)->latest()->get();
    }

    /**
     * @return Collection<int, Election>
     */
    private function announced(): Collection
    {
        return Election::query()->whereIn('status', self::ANNOUNCED)->latest('closed_at')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function publicView(string $view, array $data = []): Response
    {
        return response()
            ->view($view, $data + ['headline' => $this->headline()])
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
                'url' => route('public.candidates', $running->public_id),
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
