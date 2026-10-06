<?php

namespace App\Console\Commands;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Models\Election;
use App\Services\Permits\PermitManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Menghanguskan izin yang tidak dipakai dan mengakhiri sesi bilik yang diam (dijadwalkan tiap menit).
 */
#[Signature('pemilihan:sapu-izin')]
#[Description('Hanguskan izin memilih yang kedaluwarsa (Mode Resmi)')]
class SweepPermits extends Command
{
    public function handle(PermitManager $permits): int
    {
        $ended = Election::query()
            ->where('mode', ElectionMode::Resmi)
            ->whereIn('status', [ElectionStatus::Berlangsung, ElectionStatus::Paused])
            ->get()
            ->sum(fn (Election $election): int => $permits->expireStale($election));

        $this->info("{$ended} izin diakhiri.");

        return self::SUCCESS;
    }
}
