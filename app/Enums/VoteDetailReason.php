<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum VoteDetailReason: string implements HasLabel
{
    case SengketaHasil = 'SENGKETA_HASIL';
    case PemeriksaanKoreksi = 'PEMERIKSAAN_KOREKSI';
    case Audit = 'AUDIT';
    case Lainnya = 'LAINNYA';

    public function getLabel(): string
    {
        return match ($this) {
            self::SengketaHasil => 'Sengketa hasil',
            self::PemeriksaanKoreksi => 'Pemeriksaan koreksi suara',
            self::Audit => 'Audit',
            self::Lainnya => 'Lainnya (wajib catatan)',
        };
    }
}
