<?php

namespace App\Services\Voting;

use RuntimeException;

/**
 * Penolakan yang wajar dalam alur pemilihan. Pesannya aman ditampilkan ke pengguna.
 */
class VotingException extends RuntimeException
{
    public const ALREADY_VOTED = 'already_voted';

    public const NOT_OPEN = 'not_open';

    public const PAUSED = 'paused';

    public const TIME_UP = 'time_up';

    public const PIN_WRONG = 'pin_wrong';

    public const PIN_LOCKED = 'pin_locked';

    public const SESSION_EXPIRED = 'session_expired';

    public const INVALID_CHOICE = 'invalid_choice';

    public const INVALID_STATE = 'invalid_state';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function alreadyVoted(): self
    {
        return new self(self::ALREADY_VOTED, 'Anda sudah memilih.');
    }

    public static function notOpen(): self
    {
        return new self(self::NOT_OPEN, 'Pemungutan suara belum dibuka.');
    }

    public static function paused(): self
    {
        return new self(self::PAUSED, 'Pemungutan suara sedang dijeda. Mohon tunggu.');
    }

    public static function timeUp(): self
    {
        return new self(self::TIME_UP, 'Waktu habis. Silakan tunggu gelombang berikutnya dan hubungi panitia.');
    }

    public static function pinWrong(int $attemptsLeft): self
    {
        return new self(self::PIN_WRONG, "PIN salah. Sisa percobaan: {$attemptsLeft}.");
    }

    public static function pinLocked(): self
    {
        return new self(self::PIN_LOCKED, 'Nama ini terkunci karena PIN salah 3 kali. Silakan hubungi panitia.');
    }

    public static function sessionExpired(): self
    {
        return new self(self::SESSION_EXPIRED, 'Sesi berakhir. Silakan cari nama dan masukkan PIN lagi.');
    }

    public static function invalidChoice(): self
    {
        return new self(self::INVALID_CHOICE, 'Pilihan tidak valid. Silakan ulangi.');
    }

    public static function invalidState(string $message): self
    {
        return new self(self::INVALID_STATE, $message);
    }
}
