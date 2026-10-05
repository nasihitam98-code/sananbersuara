<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Peran yang melekat pada satu pemilihan (bukan global).
 */
enum StaffRole: string implements HasLabel
{
    case Panitia = 'PANITIA';
    case PetugasPintu = 'PETUGAS_PINTU';

    public function getLabel(): string
    {
        return match ($this) {
            self::Panitia => 'Panitia',
            self::PetugasPintu => 'Petugas Pintu',
        };
    }
}
