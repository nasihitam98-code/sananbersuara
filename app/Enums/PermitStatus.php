<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Siklus hidup izin memilih (bagian 7.7 dokumen Tahap 1).
 */
enum PermitStatus: string implements HasLabel
{
    case Aktif = 'AKTIF';
    case Dipakai = 'DIPAKAI';
    case Selesai = 'SELESAI';
    case Hangus = 'HANGUS';
    case TidakSelesai = 'TIDAK_SELESAI';
    case Dibatalkan = 'DIBATALKAN';
    case Terhenti = 'TERHENTI';

    public function getLabel(): string
    {
        return match ($this) {
            self::Aktif => 'Menunggu di bilik',
            self::Dipakai => 'Sedang memilih',
            self::Selesai => 'Selesai',
            self::Hangus => 'Hangus (tidak dipakai)',
            self::TidakSelesai => 'Tidak selesai',
            self::Dibatalkan => 'Dibatalkan petugas',
            self::Terhenti => 'Terhenti',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Aktif, self::Dipakai], true);
    }
}
