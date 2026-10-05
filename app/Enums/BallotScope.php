<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BallotScope: string implements HasLabel
{
    case PerRt = 'PER_RT';
    case RtTertentu = 'RT_TERTENTU';
    case SemuaRt = 'SEMUA_RT';
    case DaftarHadir = 'DAFTAR_HADIR';

    public function getLabel(): string
    {
        return match ($this) {
            self::PerRt => 'Per RT (kandidat tiap RT)',
            self::RtTertentu => 'RT tertentu saja',
            self::SemuaRt => 'Semua RT',
            self::DaftarHadir => 'Semua yang hadir (Mode Dadakan)',
        };
    }
}
