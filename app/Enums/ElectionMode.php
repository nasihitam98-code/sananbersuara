<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ElectionMode: string implements HasLabel
{
    case Resmi = 'RESMI';
    case Dadakan = 'DADAKAN';

    public function getLabel(): string
    {
        return match ($this) {
            self::Resmi => 'Resmi (meja + bilik)',
            self::Dadakan => 'Dadakan (QR + PIN)',
        };
    }
}
