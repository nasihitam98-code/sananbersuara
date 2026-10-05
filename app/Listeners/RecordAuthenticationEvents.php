<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Carbon;

/**
 * Login, logout, gagal login, dan lockout masuk audit log (tanpa password).
 * Didaftarkan otomatis lewat event discovery (metode handle*).
 */
class RecordAuthenticationEvents
{
    public function __construct(private AuditLogger $audit) {}

    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            $event->user->forceFill(['last_login_at' => Carbon::now()])->saveQuietly();
            $this->audit->log('auth.login', $event->user, actor: $event->user);
        }
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->log('auth.logout', $event->user, actor: $event->user);
        }
    }

    public function handleFailed(Failed $event): void
    {
        $this->audit->log('auth.failed', note: 'Percobaan login gagal', meta: [
            'email' => mb_substr((string) ($event->credentials['email'] ?? ''), 0, 190),
        ], actorType: 'guest');
    }

    public function handleLockout(Lockout $event): void
    {
        $this->audit->log('auth.lockout', meta: [
            'email' => mb_substr((string) $event->request->input('email', ''), 0, 190),
        ], actorType: 'guest');
    }
}
