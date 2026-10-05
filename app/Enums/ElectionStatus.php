<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ElectionStatus: string implements HasColor, HasLabel
{
    case Draft = 'DRAFT';
    case Ready = 'READY';
    case Berlangsung = 'BERLANGSUNG';
    case Paused = 'PAUSED';
    case Ditutup = 'DITUTUP';
    case Verifikasi = 'VERIFIKASI';
    case Published = 'PUBLISHED';
    case Unpublished = 'UNPUBLISHED';
    case Cancelled = 'CANCELLED';
    case Archived = 'ARCHIVED';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Ready => 'Siap',
            self::Berlangsung => 'Berlangsung',
            self::Paused => 'Dijeda',
            self::Ditutup => 'Ditutup',
            self::Verifikasi => 'Verifikasi',
            self::Published => 'Dipublikasikan',
            self::Unpublished => 'Ditarik dari publik',
            self::Cancelled => 'Dibatalkan',
            self::Archived => 'Diarsipkan',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft, self::Archived => 'gray',
            self::Ready => 'info',
            self::Berlangsung => 'success',
            self::Paused, self::Unpublished => 'warning',
            self::Ditutup, self::Verifikasi => 'primary',
            self::Published => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Konfigurasi inti (surat suara, kandidat, opsi hasil) hanya boleh diubah di status ini.
     */
    public function allowsConfigurationChanges(): bool
    {
        return in_array($this, [self::Draft, self::Ready], true);
    }

    /**
     * Status ketika pemungutan sedang berjalan (termasuk dijeda).
     */
    public function isLive(): bool
    {
        return in_array($this, [self::Berlangsung, self::Paused], true);
    }

    /**
     * Hasil per kandidat boleh dilihat internal mulai status ini.
     */
    public function allowsResults(): bool
    {
        return in_array($this, [self::Ditutup, self::Verifikasi, self::Published, self::Unpublished, self::Archived], true);
    }
}
