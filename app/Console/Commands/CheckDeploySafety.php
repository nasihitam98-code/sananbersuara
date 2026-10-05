<?php

namespace App\Console\Commands;

use App\Enums\ElectionStatus;
use App\Models\Election;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Dipanggil deploy.sh sebelum memasang update: menolak jika ada pemilihan yang sedang
 * berlangsung atau dijeda, supaya server tidak ter-update di tengah voting.
 */
#[Signature('pemilihan:cek-deploy')]
#[Description('Gagal jika ada pemilihan yang sedang berlangsung (pengaman sebelum deploy)')]
class CheckDeploySafety extends Command
{
    public function handle(): int
    {
        $live = Election::query()
            ->whereIn('status', [ElectionStatus::Berlangsung, ElectionStatus::Paused])
            ->pluck('name');

        if ($live->isNotEmpty()) {
            $this->error('Deploy dibatalkan: pemilihan sedang berlangsung: '.$live->implode(', '));
            $this->line('Tunggu sampai pemilihan ditutup, atau jalankan dengan FORCE=1 jika benar-benar darurat.');

            return self::FAILURE;
        }

        $this->info('Aman untuk deploy: tidak ada pemilihan yang berlangsung.');

        return self::SUCCESS;
    }
}
