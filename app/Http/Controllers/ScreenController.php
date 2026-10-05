<?php

namespace App\Http\Controllers;

use App\Enums\StaffRole;
use App\Models\Election;
use App\Models\User;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Layar proyektor untuk panitia (butuh login).
 */
class ScreenController extends Controller
{
    public function qr(Request $request, Election $election): View
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($election->isDadakan() && $user->hasElectionRole($election, StaffRole::Panitia), 403);

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
        ]);
    }
}
