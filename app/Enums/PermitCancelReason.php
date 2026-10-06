<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PermitCancelReason: string implements HasLabel
{
    case SalahKlikNama = 'SALAH_KLIK_NAMA';
    case BatalMasuk = 'PEMILIH_BATAL_MASUK';
    case BilikBermasalah = 'BILIK_BERMASALAH';
    case Lainnya = 'LAINNYA';

    public function getLabel(): string
    {
        return match ($this) {
            self::SalahKlikNama => 'Salah klik nama',
            self::BatalMasuk => 'Pemilih batal masuk bilik',
            self::BilikBermasalah => 'Bilik bermasalah',
            self::Lainnya => 'Lainnya',
        };
    }
}
