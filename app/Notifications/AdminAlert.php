<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Pemberitahuan internal untuk Super Admin / Ketua Panitia (K23). Tidak pernah dikirim ke warga.
 * Isi tidak memuat pilihan kandidat, PIN, token, atau NIK.
 */
class AdminAlert extends Notification
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public ?string $url = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('['.config('app.name').'] '.$this->title)
            ->line($this->body);

        return $this->url === null ? $message : $message->action('Buka panel', $this->url);
    }
}
