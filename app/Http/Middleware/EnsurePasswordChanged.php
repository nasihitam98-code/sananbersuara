<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Auth\EditProfile;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Akun baru (password sementara dari Super Admin) wajib mengganti password sebelum memakai panel.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs('filament.admin.auth.*') || $request->is('livewire*')) {
            return $next($request);
        }

        Notification::make()
            ->title('Ganti password sementara Anda terlebih dahulu.')
            ->warning()
            ->send();

        return redirect()->to(EditProfile::getUrl());
    }
}
