<?php

namespace App\Console\Commands;

use App\Services\Results\DataRetention;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Menjalankan kebijakan retensi data K26 (dijadwalkan tiap hari).
 */
#[Signature('pemilihan:retensi')]
#[Description('Hapus keterkaitan pemilih-pilihan setelah masa sengketa dan anonimkan data pribadi setelah 1 tahun')]
class RunDataRetention extends Command
{
    public function handle(DataRetention $retention): int
    {
        $result = $retention->run();

        $this->info("Keterkaitan dihapus: {$result['linkage']} pemilihan. Data pribadi dianonimkan: {$result['personal']} pemilihan.");

        return self::SUCCESS;
    }
}
