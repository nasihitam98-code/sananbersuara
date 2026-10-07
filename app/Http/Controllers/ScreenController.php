<?php

namespace App\Http\Controllers;

use App\Enums\ElectionStatus;
use App\Enums\StaffRole;
use App\Filament\Pages\ResultScreen;
use App\Models\Election;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Voting\ResultsCalculator;
use App\Services\Voting\VoterStatus;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Layar proyektor untuk panitia (butuh login): QR + partisipasi live. Tidak pernah memuat angka per calon.
 */
class ScreenController extends Controller
{
    public function qr(Request $request, Election $election): View
    {
        $this->authorizeScreen($request, $election);

        $url = route('voter.show', $election->access_code);

        $options = new QROptions([
            'outputBase64' => true,
            'eccLevel' => QRCode::ECC_M,
            'scale' => 10,
            'addQuietzone' => true,
        ]);

        return view('screens.qr', [
            'election' => $election,
            'url' => $url,
            'qr' => (new QRCode($options))->render($url),
            'status' => $this->status($election),
        ]);
    }

    /**
     * Status untuk layar proyektor (dibaca ulang tiap beberapa detik oleh resources/js/screen.js).
     */
    public function qrStatus(Request $request, Election $election): JsonResponse
    {
        $this->authorizeScreen($request, $election);

        return response()->json($this->status($election->refresh()))->header('Cache-Control', 'no-store');
    }

    /**
     * @return array{phase: string, wave_name: ?string, remaining: ?int, attendees: int, voted: int, not_voted: int, percent: float, had_waves: bool, now: int}
     */
    private function status(Election $election): array
    {
        $voterStatus = app(VoterStatus::class)->for($election);
        $round = $election->currentRound();
        $participation = app(ResultsCalculator::class)->participation($election, $round);
        $wave = $election->openWave();

        $phase = match (true) {
            ! in_array($election->status, [ElectionStatus::Ready, ElectionStatus::Berlangsung, ElectionStatus::Paused], true) => 'finished',
            $voterStatus['state'] === VoterStatus::OPEN => 'open',
            $voterStatus['state'] === VoterStatus::PAUSED => 'paused',
            default => 'waiting',
        };

        return [
            'phase' => $phase,
            'wave_name' => $phase === 'open' || $phase === 'paused' ? $wave?->displayName() : null,
            'remaining' => $phase === 'open' ? $voterStatus['remaining'] : null,
            'attendees' => $participation['attendees'],
            'voted' => $participation['voted'],
            'not_voted' => $participation['not_voted'],
            'percent' => $participation['percent'],
            'had_waves' => $round?->waves()->exists() ?? false,
            'now' => Carbon::now()->getTimestamp(),
        ];
    }

    /**
     * Tab proyektor hasil (tanpa menu admin): pengumuman bertahap dengan podium. Hanya setelah pemilihan
     * ditutup; membuka tab ini tercatat sebagai penayangan hasil.
     */
    public function results(Request $request, Election $election): View
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($election->status->allowsResults(), 403);
        abort_unless($user->isSuperAdmin()
            || ($election->isDadakan() ? $user->hasElectionRole($election, StaffRole::Panitia) : $user->isAdminRt()), 403);

        if ($election->results_revealed_at === null) {
            $election->forceFill(['results_revealed_at' => Carbon::now()])->save();
        }

        app(AuditLogger::class)->log('results.revealed', $election, $election, meta: ['screen' => 'tab_proyektor']);

        return view('screens.results', [
            'election' => $election,
            'blocks' => ResultScreen::resultBlocks($election, $user),
            'participation' => $election->isDadakan()
                ? app(ResultsCalculator::class)->participation($election, $election->currentRound())
                : null,
        ]);
    }

    private function authorizeScreen(Request $request, Election $election): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($election->isDadakan() && $user->hasElectionRole($election, StaffRole::Panitia), 403);
    }
}
