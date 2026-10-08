<?php

namespace App\Services\Voting;

use App\Enums\ElectionStatus;
use App\Enums\WaveKind;
use App\Enums\WaveStatus;
use App\Models\Election;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Menulis status voting ke file JSON statis (public/status/{kode}.json) setiap kali status berubah.
 *
 * Ratusan HP membaca file ini langsung dari server web tanpa melewati PHP, sehingga polling
 * tiap beberapa detik hampir tidak membebani server. File hanya memuat batas waktu absolut
 * (ends_at); sisa waktu dihitung di HP memakai jam server dari header Date.
 */
class StatusPublisher
{
    public const DIRECTORY = 'status';

    /**
     * Status mentah tanpa sisa waktu (sisa waktu dihitung saat dibaca).
     *
     * @return array{state: string, wave: ?int, round: ?int, ends_at: ?int, paused_remaining: ?int, assisted: bool}
     */
    public function build(Election $election): array
    {
        $election->refresh();
        $base = ['wave' => null, 'round' => null, 'ends_at' => null, 'paused_remaining' => null, 'assisted' => false];

        if (in_array($election->status, [ElectionStatus::Draft, ElectionStatus::Ready], true)) {
            return ['state' => VoterStatus::WAITING] + $base;
        }

        if (! $election->status->isLive()) {
            return ['state' => VoterStatus::FINISHED] + $base;
        }

        $wave = $election->openWave();

        if ($wave === null) {
            return ['state' => VoterStatus::WAITING, 'round' => $election->currentRound()?->number] + $base;
        }

        return [
            'state' => $wave->status === WaveStatus::Dijeda || $election->status === ElectionStatus::Paused ? VoterStatus::PAUSED : VoterStatus::OPEN,
            'wave' => $wave->number,
            'round' => $wave->round->number,
            'ends_at' => $wave->status === WaveStatus::Dijeda ? null : $wave->ends_at?->getTimestamp(),
            'paused_remaining' => $wave->paused_remaining_seconds,
            'assisted' => $wave->kind === WaveKind::Bantuan,
        ];
    }

    /**
     * Dipanggil setiap kali status pemilihan/gelombang berubah.
     */
    public function publish(Election $election): void
    {
        Cache::forget(VoterStatus::cacheKey($election));

        if (! $election->isDadakan()) {
            return;
        }

        $directory = public_path(self::DIRECTORY);
        File::ensureDirectoryExists($directory);

        $path = static::pathFor($election);
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        File::put($temporary, (string) json_encode($this->build($election)));

        // Windows menolak mengganti file yang sedang dibaca HP lain ("Access is denied"). Coba beberapa kali;
        // bila tetap gagal, hapus file lama agar HP beralih ke endpoint /status (selalu benar), jangan
        // biarkan status lama tampil. Tindakan panitia (buka/tutup voting) tidak boleh gagal karena ini.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (@rename($temporary, $path)) {
                return;
            }

            usleep(40_000);
        }

        @unlink($temporary);

        if (! @unlink($path)) {
            report(new \RuntimeException("File status {$path} tidak bisa diperbarui; HP memakai endpoint cadangan."));
        }
    }

    public static function pathFor(Election $election): string
    {
        return public_path(self::DIRECTORY.'/'.$election->access_code.'.json');
    }

    public static function urlFor(Election $election): string
    {
        return asset(self::DIRECTORY.'/'.$election->access_code.'.json');
    }
}
