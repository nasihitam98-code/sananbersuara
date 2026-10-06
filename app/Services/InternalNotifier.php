<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AdminAlert;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Notifikasi internal (K23): lonceng panel untuk semua Super Admin aktif (kecuali pelaku)
 * + email ke Super Admin dan alamat Ketua Panitia (VOTING_ALERT_EMAILS).
 * Kegagalan kirim email tidak boleh menggagalkan aksi utama.
 */
class InternalNotifier
{
    public function notifySuperAdmins(string $title, string $body, ?User $except = null, ?string $url = null): void
    {
        $recipients = User::role(User::ROLE_SUPER_ADMIN)
            ->where('is_active', true)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->get();

        foreach ($recipients as $recipient) {
            FilamentNotification::make()->title($title)->body($body)->warning()->sendToDatabase($recipient);
        }

        try {
            Notification::send($recipients, new AdminAlert($title, $body, $url));

            foreach (config('voting.alert_emails') as $email) {
                Notification::route('mail', $email)->notify(new AdminAlert($title, $body, $url));
            }
        } catch (Throwable $exception) {
            Log::warning('Email notifikasi internal gagal dikirim', ['title' => $title, 'error' => $exception->getMessage()]);
        }
    }
}
