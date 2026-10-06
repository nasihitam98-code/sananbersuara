<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CorrectionStatus: string implements HasColor, HasLabel
{
    case Diajukan = 'DIAJUKAN';
    case Disetujui = 'DISETUJUI';
    case Ditolak = 'DITOLAK';
    case Ditarik = 'DITARIK';

    public function getLabel(): string
    {
        return match ($this) {
            self::Diajukan => 'Menunggu Super Admin',
            self::Disetujui => 'Disetujui (suara dibatalkan)',
            self::Ditolak => 'Ditolak',
            self::Ditarik => 'Ditarik pengaju',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Diajukan => 'warning',
            self::Disetujui => 'danger',
            self::Ditolak, self::Ditarik => 'gray',
        };
    }
}
