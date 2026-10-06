<?php

namespace App\Http\Controllers;

use App\Enums\BallotScope;
use App\Enums\DeviceKind;
use App\Filament\Pages\DeskPage;
use App\Models\Ballot;
use App\Models\BallotVoter;
use App\Models\Device;
use App\Models\Permit;
use App\Services\Devices\DeviceManager;
use App\Services\Permits\BoothBallotBox;
use App\Services\Permits\PermitManager;
use App\Services\Voting\VotingException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Laptop Bilik dan pemasangan laptop Meja (Mode Resmi).
 * Bilik tidak menyimpan data apa pun; semua status diambil dari server lewat polling.
 */
class BoothController extends Controller
{
    public function __construct(
        private DeviceManager $devices,
        private PermitManager $permits,
        private BoothBallotBox $box,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $booth = $this->devices->authenticate($request, DeviceKind::Bilik);

        if ($booth === null) {
            return view('booth.pair', ['kind' => DeviceKind::Bilik]);
        }

        $this->devices->heartbeat($booth, $request);

        if ($this->box->activePermit($booth) !== null) {
            return redirect()->route('booth.ballot');
        }

        return view('booth.wait', ['booth' => $booth]);
    }

    public function pairForm(): View
    {
        return view('booth.pair', ['kind' => DeviceKind::Meja]);
    }

    public function pair(Request $request, string $kind): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:20'],
            'label' => ['required', 'string', 'min:3', 'max:120'],
        ], [
            'token.required' => 'Masukkan token dari petugas.',
            'label.required' => 'Isi nama/label laptop, mis. "Asus Pak Budi".',
        ]);

        $expected = $kind === 'meja' ? DeviceKind::Meja : DeviceKind::Bilik;

        try {
            $result = $this->devices->pair($validated['token'], $validated['label'], $request, $expected);
        } catch (VotingException $exception) {
            return back()->withErrors(['token' => $exception->getMessage()])->withInput($request->only('label'));
        }

        $cookie = $this->devices->cookieFor($result['device'], $result['secret']);

        return $expected === DeviceKind::Meja
            ? redirect()->to(DeskPage::getUrl())->withCookie($cookie)
            : redirect()->route('booth.show')->withCookie($cookie);
    }

    public function status(Request $request): JsonResponse
    {
        $booth = $this->devices->authenticate($request, DeviceKind::Bilik);

        if ($booth === null) {
            return response()->json(['state' => 'unpaired']);
        }

        $this->devices->heartbeat($booth, $request);

        // Pembersihan izin kedaluwarsa dijalankan paling sering tiap 10 detik per pemilihan.
        if (Cache::add("permit-sweep:{$booth->election_id}", true, 10)) {
            $this->permits->expireStale($booth->election);
        }

        $permit = $this->box->activePermit($booth);

        return response()->json([
            'state' => $permit === null ? 'waiting' : 'voting',
            'booth' => $booth->code(),
            'election' => $booth->election->status->value,
        ]);
    }

    public function ballot(Request $request): View|RedirectResponse
    {
        [$booth, $permit] = $this->context($request);

        if ($permit === null) {
            return redirect()->route('booth.show');
        }

        $pending = $this->permits->pendingBallots($permit->voter, $booth->election);
        $ballot = $pending->first();

        if ($ballot === null) {
            return redirect()->route('booth.done');
        }

        $entry = BallotVoter::query()->where('ballot_id', $ballot->id)->where('voter_id', $permit->voter_id)->first();
        $candidates = $ballot->ballotCandidates()
            ->when($ballot->scope === BallotScope::PerRt, fn ($query) => $query->where('unit_id', $entry?->unit_id))
            ->get();

        $all = $this->permits->eligibleBallotIds($permit->voter, $booth->election);
        $ordered = $booth->election->ballots()->whereIn('id', $all)->pluck('id')->values();

        return view('booth.ballot', [
            'booth' => $booth,
            'ballot' => $ballot,
            'candidates' => $candidates,
            'ballotIndex' => $ordered->search($ballot->id) + 1,
            'ballotTotal' => $ordered->count(),
        ]);
    }

    public function touch(Request $request): JsonResponse
    {
        [, $permit] = $this->context($request);

        if ($permit === null) {
            return response()->json(['state' => 'waiting'], 409);
        }

        $this->permits->touch($permit);

        return response()->json(['state' => 'voting']);
    }

    public function cast(Request $request): RedirectResponse
    {
        [$booth, $permit] = $this->context($request);

        if ($permit === null) {
            return redirect()->route('booth.show');
        }

        $validated = $request->validate([
            'ballot' => ['required', 'string', 'size:26'],
            'candidate' => ['required', 'string', 'size:26'],
        ]);

        $ballot = Ballot::query()->where('public_id', $validated['ballot'])->where('election_id', $booth->election_id)->first();
        $candidate = $ballot?->ballotCandidates()->where('public_id', $validated['candidate'])->first();

        if ($ballot === null || $candidate === null) {
            return back()->withErrors(['candidate' => VotingException::invalidChoice()->getMessage()]);
        }

        try {
            $result = $this->box->cast($booth, $permit, $ballot, $candidate);
        } catch (VotingException $exception) {
            if ($exception->reason === VotingException::ALREADY_VOTED) {
                return redirect()->route('booth.ballot');
            }

            if ($exception->reason === VotingException::INVALID_CHOICE) {
                return back()->withErrors(['candidate' => $exception->getMessage()]);
            }

            return redirect()->route('booth.show');
        }

        return $result['finished'] ? redirect()->route('booth.done') : redirect()->route('booth.ballot');
    }

    public function done(Request $request): View|RedirectResponse
    {
        $booth = $this->devices->authenticate($request, DeviceKind::Bilik);

        if ($booth === null) {
            return redirect()->route('booth.show');
        }

        return view('booth.done', ['booth' => $booth]);
    }

    /**
     * @return array{0: Device, 1: ?Permit}
     */
    private function context(Request $request): array
    {
        $booth = $this->devices->authenticate($request, DeviceKind::Bilik);
        abort_if($booth === null, 403);

        $this->devices->heartbeat($booth, $request);

        return [$booth, $this->box->activePermit($booth)];
    }
}
