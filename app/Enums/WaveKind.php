<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WaveKind: string implements HasLabel
{
    /** Semua peserta yang belum memilih, memakai HP sendiri, dengan timer. */
    case Terbuka = 'TERBUKA';

    /** Untuk yang belum memilih (mis. lansia tanpa HP), memakai HP pinjaman panitia, ditutup manual. */
    case Bantuan = 'BANTUAN';

    public function getLabel(): string
    {
        return match ($this) {
            self::Terbuka => 'Gelombang terbuka (HP sendiri, ber-timer)',
            self::Bantuan => 'Gelombang bantuan (HP pinjaman, tutup manual)',
        };
    }
}
