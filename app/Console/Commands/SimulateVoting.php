<?php

namespace App\Console\Commands;

use App\Enums\BallotScope;
use App\Enums\CandidateStatus;
use App\Enums\DeviceKind;
use App\Enums\DeviceReleaseReason;
use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\VoteStatus;
use App\Models\Ballot;
use App\Models\BallotVoter;
use App\Models\Candidate;
use App\Models\Device;
use App\Models\Election;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vote;
use App\Models\Voter;
use App\Services\Devices\DeviceManager;
use App\Services\Permits\BoothBallotBox;
use App\Services\Permits\PermitManager;
use App\Services\Voting\VotingException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Gladi penuh Mode Resmi: setiap pemilih berhak diberi izin dari Meja RT-nya (akun Admin RT) lalu mencoblos di bilik
 * RT-nya, lewat layanan yang sama dengan laptop asli. Suara disebar merata selama --menit agar quick count terlihat
 * bergerak. Laptop yang dipasang perintah ini berlabel SIMULASI dan dilepas lagi di akhir. Setelah gladi: Tutup,
 * verifikasi, publikasikan, lalu "Ulang dari awal".
 */
#[Signature('pemilihan:simulasi {--konfirmasi= : Nama pemilihan persis, sebagai pengaman} {--menit=30 : Lama penyebaran suara}')]
#[Description('Gladi: semua pemilih mencoblos lewat Meja dan Bilik RT masing-masing (Mode Resmi)')]
class SimulateVoting extends Command
{
    public const LABEL = 'SIMULASI';

    public function handle(DeviceManager $devices, PermitManager $permits, BoothBallotBox $box): int
    {
        $election = Election::query()->where('mode', ElectionMode::Resmi)->where('status', ElectionStatus::Berlangsung)->latest('id')->first();

        if ($election === null) {
            $this->error('Tidak ada pemilihan Mode Resmi yang Berlangsung. Tekan Mulai dulu.');

            return self::FAILURE;
        }

        if ($this->option('konfirmasi') !== $election->name) {
            $this->error("Ketik nama pemilihan persis: --konfirmasi=\"{$election->name}\"");

            return self::FAILURE;
        }

        $realVotes = Vote::query()->where('election_id', $election->id)
            ->whereNotIn('device_id', Device::query()->where('election_id', $election->id)->where('label', self::LABEL)->select('id'))
            ->count();

        if ($realVotes > 0) {
            $this->error("Sudah ada {$realVotes} suara dari laptop asli. Simulasi hanya untuk gladi, tidak dijalankan.");

            return self::FAILURE;
        }

        $units = Unit::query()
            ->whereIn('id', BallotVoter::query()->whereIn('ballot_id', $election->ballots()->select('id'))->whereNull('revoked_at')->select('unit_id'))
            ->orderBy('sort')
            ->get();
        $setups = [];

        foreach ($units as $unit) {
            $officer = User::role(User::ROLE_ADMIN_RT)->permission(User::PERMISSION_DESK)
                ->where('unit_id', $unit->id)->where('is_active', true)->orderBy('id')->first();

            if ($officer === null) {
                $this->error("{$unit->name} belum punya akun Admin RT dengan izin Meja.");

                return self::FAILURE;
            }

            $desk = Device::query()->where('election_id', $election->id)->where('unit_id', $unit->id)->where('kind', DeviceKind::Meja)->firstOrFail();
            $booth = $this->simulationBooth($election, $unit);

            if ($booth === null) {
                $this->error("Semua bilik {$unit->name} sedang dipasang laptop asli. Lepas satu bilik dulu.");

                return self::FAILURE;
            }

            $setups[$unit->id] = [
                'unit' => $unit,
                'officer' => $officer,
                'desk' => $desk->isPaired() ? $desk : $this->pairForSimulation($devices, $desk, $officer),
                'booth' => $booth->isPaired() ? $booth : $this->pairForSimulation($devices, $booth, $officer),
            ];
        }

        $queue = $this->interleavedVoterQueue($election, array_keys($setups), $permits);
        $delayMicroseconds = $queue->isEmpty() ? 0 : (int) (max(0, (float) $this->option('menit')) * 60_000_000 / $queue->count());
        $this->info("{$queue->count()} pemilih akan mencoblos selama ±{$this->option('menit')} menit.");

        $weights = [];
        $done = 0;

        foreach ($queue as $voter) {
            $setup = $setups[$voter->unit_id];
            $this->keepAlive($setup['desk'], $setup['booth']);

            try {
                $permit = $permits->grant($voter, $setup['officer'], $setup['desk']);

                foreach ($permits->pendingBallots($voter, $election) as $ballot) {
                    $box->cast($setup['booth'], $permit, $ballot, $this->pickCandidate($ballot, $voter, $weights));
                }
            } catch (VotingException $exception) {
                $this->warn("{$voter->name} ({$setup['unit']->name}): {$exception->getMessage()}");

                continue;
            }

            $done++;

            if ($done % 100 === 0) {
                $this->line(now()->format('H:i:s')." · {$done} dari {$queue->count()} sudah mencoblos");
            }

            usleep($delayMicroseconds);
        }

        Device::query()->whereKey(collect($setups)->flatMap(fn (array $setup): array => [$setup['desk']->id, $setup['booth']->id]))
            ->where('label', self::LABEL)
            ->whereNotNull('session_secret_hash')
            ->get()
            ->each(fn (Device $device) => $devices->release($device, DeviceReleaseReason::Lainnya, null));

        $this->info("Selesai: {$done} pemilih mencoblos. Perkiraan hasil (bandingkan dengan quick count dan hasil resmi):");
        $this->printTally($election);

        return self::SUCCESS;
    }

    /**
     * Bilik yang sudah dipasang simulasi sebelumnya (lanjutan), atau bilik kosong pertama.
     */
    private function simulationBooth(Election $election, Unit $unit): ?Device
    {
        $booths = Device::query()->where('election_id', $election->id)->where('unit_id', $unit->id)->where('kind', DeviceKind::Bilik)->orderBy('number')->get();

        return $booths->first(fn (Device $booth): bool => $booth->isPaired() && $booth->label === self::LABEL)
            ?? $booths->first(fn (Device $booth): bool => ! $booth->isPaired());
    }

    private function pairForSimulation(DeviceManager $devices, Device $device, User $officer): Device
    {
        return $devices->pair($devices->issueToken($device, $officer), self::LABEL, Request::create('/'), $device->kind)['device'];
    }

    /**
     * Pemilih berhak yang belum selesai mencoblos, diselang-seling antar RT agar semua RT bergerak bersamaan.
     *
     * @param  array<int, int>  $unitIds
     * @return Collection<int, Voter>
     */
    private function interleavedVoterQueue(Election $election, array $unitIds, PermitManager $permits): Collection
    {
        $voterIds = BallotVoter::query()
            ->whereIn('ballot_id', $election->ballots()->select('id'))
            ->whereNull('revoked_at')
            ->select('voter_id');

        $perUnit = Voter::query()->whereIn('id', $voterIds)->whereIn('unit_id', $unitIds)
            ->orderBy('rt_number')->orderBy('id')->get()
            ->filter(fn (Voter $voter): bool => $permits->pendingBallots($voter, $election)->isNotEmpty())
            ->groupBy('unit_id')
            ->map(fn (Collection $voters): array => $voters->values()->all());

        $longest = $perUnit->max(fn (array $voters): int => count($voters)) ?? 0;

        return collect(range(0, max(0, $longest - 1)))
            ->flatMap(fn (int $index): array => $perUnit->map(fn (array $voters): ?Voter => $voters[$index] ?? null)->filter()->values()->all())
            ->values();
    }

    /**
     * Laptop simulasi dianggap tetap tersambung (pengganti detak jantung laptop asli).
     */
    private function keepAlive(Device ...$devices): void
    {
        Device::query()->whereKey(array_map(fn (Device $device): int => $device->id, $devices))->update(['last_seen_at' => Carbon::now()]);
    }

    /**
     * Pilihan acak berbobot tetap per surat suara/RT (mis. 50/30/20%) agar ada pemenang yang jelas, bukan seri.
     *
     * @param  array<string, array<int, array{candidate: Candidate, weight: int}>>  $weights
     */
    private function pickCandidate(Ballot $ballot, Voter $voter, array &$weights): Candidate
    {
        $entry = BallotVoter::query()->where('ballot_id', $ballot->id)->where('voter_id', $voter->id)->firstOrFail();
        $key = $ballot->id.':'.($ballot->scope === BallotScope::PerRt ? $entry->unit_id : 'all');

        $weights[$key] ??= $ballot->candidates()
            ->where('status', CandidateStatus::Aktif)
            ->when($ballot->scope === BallotScope::PerRt, fn ($query) => $query->where('unit_id', $entry->unit_id))
            ->get()
            ->shuffle()
            ->values()
            ->map(fn (Candidate $candidate, int $index): array => ['candidate' => $candidate, 'weight' => max(1, 50 - $index * 15)])
            ->all();

        $roll = random_int(1, array_sum(array_column($weights[$key], 'weight')));

        foreach ($weights[$key] as $option) {
            $roll -= $option['weight'];

            if ($roll <= 0) {
                return $option['candidate'];
            }
        }

        return $weights[$key][0]['candidate'];
    }

    private function printTally(Election $election): void
    {
        $rows = Vote::query()->where('election_id', $election->id)->where('status', VoteStatus::Sah)
            ->with(['ballot', 'candidate.unit'])
            ->get()
            ->groupBy('candidate_id')
            ->map(fn (Collection $votes): array => [
                $votes->first()->ballot->title,
                $votes->first()->candidate->unit?->name ?? '-',
                $votes->first()->candidate->displayNumber(),
                $votes->first()->candidate->name,
                $votes->count(),
            ])
            ->sortBy(fn (array $row): string => $row[0].$row[1].$row[2])
            ->values()
            ->all();

        $this->table(['Surat suara', 'RT', 'No.', 'Calon', 'Suara'], $rows);
    }
}
