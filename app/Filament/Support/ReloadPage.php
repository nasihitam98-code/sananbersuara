<?php

namespace App\Filament\Support;

use Filament\Facades\Filament;
use Livewire\Component;

/**
 * Muat ulang halaman panel setelah status pemilihan berubah (Siap, Berlangsung, Ditutup, ...): lencana status di
 * header dan menu kiri (mis. Layar Hasil yang baru muncul setelah ditutup) dirender di luar komponen Livewire,
 * jadi hanya segar lewat muat ulang. Notifikasi Filament yang sudah dikirim tetap tampil setelah muat ulang.
 */
class ReloadPage
{
    public static function now(Component $livewire): void
    {
        // Pada permintaan Livewire, Referer = alamat halaman yang sedang dibuka.
        $livewire->redirect(request()->header('Referer') ?: Filament::getUrl());
    }
}
