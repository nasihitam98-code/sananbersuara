<?php

namespace App\Services\Voters;

use RuntimeException;

/**
 * Nama sama sudah terdaftar (tanpa NIK/tanggal lahir yang sama). Petugas harus menyatakan "orang berbeda".
 */
class DuplicateNameWarning extends RuntimeException
{
    /**
     * @param  array<int, string>  $similar
     */
    public function __construct(public readonly array $similar)
    {
        parent::__construct('Nama yang sama sudah terdaftar: '.implode('; ', $similar));
    }
}
