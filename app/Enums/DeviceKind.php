<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DeviceKind: string implements HasLabel
{
    case Meja = 'MEJA';
    case Bilik = 'BILIK';

    public function getLabel(): string
    {
        return match ($this) {
            self::Meja => 'Meja Izin',
            self::Bilik => 'Bilik',
        };
    }
}
