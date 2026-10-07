<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WaveKind: string implements HasLabel
{
    /** Semua peserta yang belum memilih, memakai HP sendiri. Timer opsional. */
    case Terbuka = 'TERBUKA';

    /** Untuk yang belum memilih (mis. lansia tanpa HP), memakai HP pinjaman panitia; suara ditandai "Dibantu". Timer opsional. */
    case Bantuan = 'BANTUAN';

    public function getLabel(): string
    {
        return match ($this) {
            self::Terbuka => 'Terbuka: warga memakai HP sendiri',
            self::Bantuan => 'Bantuan: HP panitia/pinjaman (ditandai "Dibantu")',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Terbuka => 'Terbuka',
            self::Bantuan => 'Bantuan',
        };
    }
}
