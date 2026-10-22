<?php

namespace App\Filament\Resources\Concerns;

/**
 * Setelah Simpan/Buat, kembali ke tabel daftar (bukan tetap di form) agar hasilnya langsung terlihat.
 * "Buat & buat lainnya" tetap di form seperti bawaan Filament.
 */
trait RedirectsToIndex
{
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
