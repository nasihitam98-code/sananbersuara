<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CandidateStatus: string implements HasLabel
{
    case Aktif = 'AKTIF';
    case Mundur = 'MUNDUR';

    public function getLabel(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::Mundur => 'Mengundurkan diri',
        };
    }
}
