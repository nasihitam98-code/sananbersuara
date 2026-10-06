<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TpsPauseReason: string implements HasLabel
{
    case InternetMati = 'INTERNET_MATI';
    case ListrikMati = 'LISTRIK_MATI';
    case GangguanPerangkat = 'GANGGUAN_PERANGKAT';
    case GangguanKeamanan = 'GANGGUAN_KEAMANAN';
    case Lainnya = 'LAINNYA';

    public function getLabel(): string
    {
        return match ($this) {
            self::InternetMati => 'Internet mati',
            self::ListrikMati => 'Listrik mati',
            self::GangguanPerangkat => 'Gangguan perangkat',
            self::GangguanKeamanan => 'Gangguan keamanan/ketertiban',
            self::Lainnya => 'Lainnya',
        };
    }
}
