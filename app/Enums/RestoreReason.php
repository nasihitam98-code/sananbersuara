<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Alasan "Pulihkan Hak Pilih" (Mode Dadakan).
 */
enum RestoreReason: string implements HasLabel
{
    case NamaDipakaiOrangLain = 'NAMA_DIPAKAI_ORANG_LAIN';
    case PinTerkunci = 'PIN_TERKUNCI';
    case PinHilang = 'PIN_HILANG';
    case GangguanTeknis = 'GANGGUAN_TEKNIS';
    case Lainnya = 'LAINNYA';

    public function getLabel(): string
    {
        return match ($this) {
            self::NamaDipakaiOrangLain => 'Nama dipakai orang lain',
            self::PinTerkunci => 'PIN terkunci',
            self::PinHilang => 'PIN hilang/lupa',
            self::GangguanTeknis => 'Gangguan teknis',
            self::Lainnya => 'Lainnya (wajib catatan)',
        };
    }
}
