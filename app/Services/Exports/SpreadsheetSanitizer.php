<?php

namespace App\Services\Exports;

/**
 * Pengaman isi Excel: teks yang diawali karakter pemicu rumus diberi awalan apostrof
 * (anti CSV/formula injection), mis. nama peserta "=HYPERLINK(...)".
 */
class SpreadsheetSanitizer
{
    /**
     * @param  array<int, mixed>  $values
     * @return array<int, mixed>
     */
    public static function row(array $values): array
    {
        return array_map(
            fn (mixed $value): mixed => is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value,
            $values,
        );
    }
}
