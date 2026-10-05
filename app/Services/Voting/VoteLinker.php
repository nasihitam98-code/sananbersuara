<?php

namespace App\Services\Voting;

use App\Models\Attendee;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Tautan sementara peserta-suara untuk Mode Dadakan (keputusan K25, Opsi A).
 *
 * Suara menyimpan HMAC(kunci server, peserta + surat suara + putaran), bukan ID peserta.
 * Tautan hanya bisa dihitung ulang oleh server yang memegang VOTE_LINK_KEY, dan seluruh
 * kolom voter_link dikosongkan saat pemilihan ditutup.
 */
class VoteLinker
{
    public function link(Attendee $attendee, int $ballotId, int $roundId): string
    {
        return hash_hmac('sha256', "{$attendee->public_id}|{$ballotId}|{$roundId}", $this->key());
    }

    private function key(): string
    {
        $key = (string) config('voting.vote_link_key');

        if ($key === '') {
            throw new RuntimeException('VOTE_LINK_KEY belum diatur di .env.');
        }

        return Str::startsWith($key, 'base64:') ? base64_decode(Str::after($key, 'base64:')) : $key;
    }
}
