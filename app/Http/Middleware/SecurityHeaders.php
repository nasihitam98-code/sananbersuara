<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan untuk semua respons web. Halaman pemilih dan publik mendapat CSP ketat;
 * panel admin (Filament/Livewire) memakai CSP yang lebih longgar karena butuh skrip inline.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $response->headers->set('Content-Security-Policy', $this->policyFor($request));

        return $response;
    }

    private function policyFor(Request $request): string
    {
        $dev = Vite::isRunningHot() ? ' '.rtrim((string) file_get_contents(public_path('hot'))) : '';
        $devSocket = $dev === '' ? '' : ' '.str_replace(['http://', 'https://'], ['ws://', 'wss://'], trim($dev));

        if ($request->is('admin', 'admin/*', 'livewire*', 'filament/*')) {
            return "default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'{$dev}; script-src 'self' 'unsafe-inline' 'unsafe-eval'{$dev}; font-src 'self' data:; connect-src 'self'{$dev}{$devSocket}; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";
        }

        return "default-src 'self'; img-src 'self' data:; style-src 'self'{$dev}; script-src 'self'{$dev}; font-src 'self'; connect-src 'self'{$dev}{$devSocket}; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";
    }
}
