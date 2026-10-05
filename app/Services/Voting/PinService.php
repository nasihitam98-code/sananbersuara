<?php

namespace App\Services\Voting;

use App\Models\Attendee;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

/**
 * PIN Mode Dadakan: dibuat acak, disimpan sebagai HMAC berpepper, tampil sekali ke petugas pintu.
 */
class PinService
{
    /**
     * PIN yang terlalu mudah ditebak tidak pernah diberikan.
     *
     * @var array<int, string>
     */
    private const WEAK_PINS = ['0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999', '1234', '4321', '0123', '9876', '1212', '2580'];

    public function generate(): string
    {
        $length = (int) config('voting.defaults.pin_length');

        do {
            $pin = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
        } while (in_array($pin, self::WEAK_PINS, true));

        return $pin;
    }

    /**
     * Hash terikat pada peserta (public_id), jadi PIN yang sama milik dua orang menghasilkan hash berbeda.
     */
    public function hash(string $attendeePublicId, #[SensitiveParameter] string $pin): string
    {
        return hash_hmac('sha256', $attendeePublicId.'|'.$pin, $this->pepper());
    }

    public function matches(Attendee $attendee, #[SensitiveParameter] string $pin): bool
    {
        return hash_equals($attendee->pin_hash, $this->hash($attendee->public_id, $pin));
    }

    private function pepper(): string
    {
        $pepper = (string) config('voting.pin_pepper');

        if ($pepper === '') {
            throw new RuntimeException('PIN_PEPPER belum diatur di .env.');
        }

        return Str::startsWith($pepper, 'base64:') ? base64_decode(Str::after($pepper, 'base64:')) : $pepper;
    }
}
