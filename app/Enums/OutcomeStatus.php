<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OutcomeStatus: string implements HasLabel
{
    case Ditetapkan = 'DITETAPKAN';
    case BelumDitetapkan = 'BELUM_DITETAPKAN';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ditetapkan => 'Ditetapkan',
            self::BelumDitetapkan => 'Belum ditetapkan (mis. seri, menunggu putaran 2)',
        };
    }
}
