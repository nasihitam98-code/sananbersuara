<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DeviceReleaseReason: string implements HasLabel
{
    case Rusak = 'RUSAK';
    case BateraiHabis = 'BATERAI_HABIS';
    case Hilang = 'HILANG';
    case Diganti = 'DIGANTI';
    case PemilihanDitutup = 'PEMILIHAN_DITUTUP';
    case Lainnya = 'LAINNYA';

    public function getLabel(): string
    {
        return match ($this) {
            self::Rusak => 'Laptop rusak',
            self::BateraiHabis => 'Baterai habis',
            self::Hilang => 'Hilang',
            self::Diganti => 'Diganti laptop lain',
            self::PemilihanDitutup => 'Pemilihan ditutup',
            self::Lainnya => 'Lainnya',
        };
    }
}
