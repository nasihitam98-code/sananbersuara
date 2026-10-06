<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Alasan tambah pemilih saat pemilihan berlangsung (jalur darurat, bagian 5A).
 */
enum EmergencyAddReason: string implements HasLabel
{
    case BelumAdaDiDataAwal = 'BELUM_ADA_DI_DATA_AWAL';
    case SalahKetikDataAwal = 'SALAH_KETIK_DATA_AWAL';
    case DisahkanPanitia = 'DISAHKAN_PANITIA';
    case Lainnya = 'LAINNYA';

    public function getLabel(): string
    {
        return match ($this) {
            self::BelumAdaDiDataAwal => 'Nama belum ada di data awal',
            self::SalahKetikDataAwal => 'Salah ketik di data awal (nama tidak ditemukan)',
            self::DisahkanPanitia => 'Pemilih baru yang disahkan panitia',
            self::Lainnya => 'Lainnya',
        };
    }
}
