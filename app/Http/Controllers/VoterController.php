<?php

namespace App\Http\Controllers;

use App\Enums\ElectionMode;
use App\Models\Attendee;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Wave;
use App\Services\Voting\BallotBox;
use App\Services\Voting\RoundResolver;
use App\Services\Voting\VoterStatus;
use App\Services\Voting\VotingException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Alur HP pemilih Mode Dadakan: tunggu -> cari nama -> PIN -> pilih -> konfirmasi -> selesai.
 *
 * Sesi pemilih hanya berisi ID sementara (kunci "voter") dan tidak memberi akses ke panel admin.
 */
class VoterController extends Controller
{
    private const SESSION_KEY = 'voter';

    public function __construct(
        private BallotBox $box,
        private VoterStatus $status,
    ) {}

    public function show(string $accessCode): View
    {
        $election = $this->resolveElection($accessCode);
        $status = $this->status->for($election);
        $previews = $election->ballots()->with('ballotCandidates')->get()->flatMap->ballotCandidates;

        return view('voter.start', [
            'election' => $election,
            'status' => $status,
            'thumbs' => $previews->map(fn (Candidate $candidate): ?string => $candidate->photoUrl('thumb'))->filter()->values(),
        ]);
    }

    public function status(string $accessCode): JsonResponse
    {
        $election = $this->resolveElection($accessCode);

        return response()->json($this->status->for($election))->header('Cache-Control', 'no-store');
    }

    public function search(Request $request, string $accessCode): JsonResponse
    {
        $election = $this->resolveElection($accessCode);
        $query = (string) $request->string('q')->limit(60, '');

        try {
            $results = $this->box->search($election, $query);
        } catch (VotingException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'results' => []], 409);
        }

        // Nomor hadir selalu ikut tampil (juga tercetak di kartu PIN) agar nama kembar bisa dibedakan.
        return response()->json([
            'results' => $results->map(fn (Attendee $attendee): array => [
                'id' => $attendee->public_id,
                'name' => $attendee->name,
                'detail' => ($attendee->unit ? $attendee->unit->name.' · ' : '').'No. hadir '.$attendee->displayNumber(),
            ])->values(),
            'more' => $results->count() >= (int) $election->setting('search_max_results'),
        ]);
    }

    public function pinForm(string $accessCode, string $attendeeId): View|RedirectResponse
    {
        $election = $this->resolveElection($accessCode);
        $attendee = $this->resolveAttendee($election, $attendeeId);

        return view('voter.pin', [
            'election' => $election,
            'attendee' => $attendee,
            'status' => $this->status->for($election),
        ]);
    }

    public function verifyPin(Request $request, string $accessCode, string $attendeeId): RedirectResponse
    {
        $election = $this->resolveElection($accessCode);
        $attendee = $this->resolveAttendee($election, $attendeeId);

        $validated = $request->validate([
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
        ], [
            'pin.required' => 'Masukkan PIN dari kertas Anda.',
            'pin.regex' => 'PIN berupa angka (4 digit).',
        ]);

        try {
            $wave = $this->box->verifyPin($election, $attendee, $validated['pin']);
        } catch (VotingException $exception) {
            if ($exception->reason === VotingException::ALREADY_VOTED) {
                return redirect()->route('voter.done', $accessCode)->with('already', true);
            }

            return back()->withErrors(['pin' => $exception->getMessage()]);
        }

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, [
            'election' => $election->id,
            'attendee' => $attendee->id,
            'wave' => $wave->id,
            'expires' => Carbon::now()->addMinutes((int) $election->setting('voter_session_minutes'))->getTimestamp(),
        ]);

        return redirect()->route('voter.ballot', $accessCode);
    }

    public function ballot(Request $request, string $accessCode): View|RedirectResponse
    {
        $election = $this->resolveElection($accessCode);

        try {
            [$attendee, $wave] = $this->voterFromSession($request, $election);
        } catch (VotingException $exception) {
            return redirect()->route('voter.show', $accessCode)->with('notice', $exception->getMessage());
        }

        $ballot = $this->box->pendingBallots($election, $attendee, $wave)->first();

        if ($ballot === null) {
            return $this->finish($request, $accessCode);
        }

        $resolver = app(RoundResolver::class);
        $allowed = $resolver->allowedCandidateIds($wave->round);
        $inRound = $resolver->ballotIds($election, $wave->round);

        return view('voter.ballot', [
            'election' => $election,
            'ballot' => $ballot,
            'candidates' => $ballot->ballotCandidates()->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))->get(),
            'status' => $this->status->for($election),
            'ballotTotal' => $inRound->count(),
            'ballotIndex' => $inRound->search($ballot->id) + 1,
        ]);
    }

    public function cast(Request $request, string $accessCode): RedirectResponse
    {
        $election = $this->resolveElection($accessCode);

        try {
            [$attendee, $wave] = $this->voterFromSession($request, $election);
        } catch (VotingException $exception) {
            return redirect()->route('voter.show', $accessCode)->with('notice', $exception->getMessage());
        }

        $validated = $request->validate([
            'ballot' => ['required', 'string', 'size:26'],
            'candidate' => ['required', 'string', 'size:26'],
        ]);

        $ballot = Ballot::query()->where('public_id', $validated['ballot'])->where('election_id', $election->id)->first();
        $candidate = $ballot?->ballotCandidates()->where('public_id', $validated['candidate'])->first();

        if ($ballot === null || $candidate === null) {
            return back()->withErrors(['candidate' => VotingException::invalidChoice()->getMessage()]);
        }

        try {
            $this->box->cast($election, $attendee, $wave, $ballot, $candidate);
        } catch (VotingException $exception) {
            if ($exception->reason === VotingException::ALREADY_VOTED) {
                return redirect()->route('voter.ballot', $accessCode);
            }

            if ($exception->reason === VotingException::INVALID_CHOICE) {
                return back()->withErrors(['candidate' => $exception->getMessage()]);
            }

            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('voter.show', $accessCode)->with('notice', $exception->getMessage());
        }

        return redirect()->route('voter.ballot', $accessCode);
    }

    public function done(Request $request, string $accessCode): View
    {
        $election = $this->resolveElection($accessCode);
        $request->session()->forget(self::SESSION_KEY);

        return view('voter.done', [
            'election' => $election,
            'status' => $this->status->for($election),
            'already' => (bool) $request->session()->get('already', false),
        ]);
    }

    public function leave(Request $request, string $accessCode): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->regenerate();

        return redirect()->route('voter.show', $accessCode);
    }

    private function finish(Request $request, string $accessCode): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->regenerate();

        return redirect()->route('voter.done', $accessCode);
    }

    /**
     * @return array{0: Attendee, 1: Wave}
     */
    private function voterFromSession(Request $request, Election $election): array
    {
        $data = $request->session()->get(self::SESSION_KEY);

        if (! is_array($data) || ($data['election'] ?? null) !== $election->id || ($data['expires'] ?? 0) < Carbon::now()->getTimestamp()) {
            $request->session()->forget(self::SESSION_KEY);

            throw VotingException::sessionExpired();
        }

        $attendee = Attendee::query()->where('election_id', $election->id)->find($data['attendee']);
        $wave = Wave::query()->find($data['wave']);

        if ($attendee === null || $wave === null) {
            throw VotingException::sessionExpired();
        }

        return [$attendee, $wave];
    }

    private function resolveElection(string $accessCode): Election
    {
        return Election::query()
            ->where('access_code', $accessCode)
            ->where('mode', ElectionMode::Dadakan)
            ->firstOrFail();
    }

    private function resolveAttendee(Election $election, string $attendeeId): Attendee
    {
        return Attendee::query()
            ->where('election_id', $election->id)
            ->where('public_id', $attendeeId)
            ->firstOrFail();
    }
}
