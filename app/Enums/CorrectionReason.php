<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CorrectionReason: string implements HasLabel
{
    case SalahOrang = 'IZIN_SALAH_ORANG';
    case Dipaksa = 'PEMILIH_DIPAKSA';
    case GangguanTeknis = 'GANGGUAN_TEKNIS';
    case TidakBerhak = 'PEMILIH_TIDAK_BERHAK';
    case Lainnya = 'LAINNYA';

    public function getLabel(): string
    {
        return match ($this) {
            self::SalahOrang => 'Izin diberikan ke orang yang salah dan suara sudah masuk',
            self::Dipaksa => 'Pemilih dipaksa/diintimidasi',
            self::GangguanTeknis => 'Gangguan teknis bilik',
            self::TidakBerhak => 'Pemilih ternyata tidak berhak',
            self::Lainnya => 'Lainnya (wajib catatan)',
        };
    }
}
