<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReportStatus: string implements HasColor, HasLabel
{
    case Draft = 'DRAFT';
    case Disahkan = 'DISAHKAN';
    case Digantikan = 'DIGANTIKAN';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Disahkan => 'Disahkan',
            self::Digantikan => 'Digantikan',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Disahkan => 'success',
            self::Digantikan => 'gray',
        };
    }
}
