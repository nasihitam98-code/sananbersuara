<?php

namespace App\Filament\Support;

use App\Models\GalleryPhoto;
use Filament\Forms\Components\ViewField;

/**
 * Pemilih gambar dari Media berbentuk grid (klik untuk memilih), dengan pencarian nama file.
 * Dipakai di mana pun gambar dipilih: foto calon (form, tombol Foto, menu Media) dan logo.
 */
class MediaPicker
{
    public static function field(string $name, string $label = 'Atau pilih dari Media'): ViewField
    {
        return ViewField::make($name)
            ->label($label)
            ->view('filament.forms.gallery-picker')
            ->viewData(fn (): array => ['photos' => GalleryPhoto::query()->with('candidates')->latest()->limit(300)->get()])
            ->rule('exists:gallery_photos,id');
    }
}
