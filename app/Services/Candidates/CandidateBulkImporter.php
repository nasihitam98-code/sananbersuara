<?php

namespace App\Services\Candidates;

use App\Enums\BallotScope;
use App\Enums\CandidateStatus;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CandidatePhotoProcessor;
use App\Services\Voting\VotingException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Tambah banyak calon sekaligus dari teks tempelan (satu calon per baris) dan pasangkan
 * banyak foto berdasarkan nomor di nama file. Semua baris diperiksa dulu; satu baris
 * bermasalah = tidak ada yang disimpan.
 */
class CandidateBulkImporter
{
    public function __construct(
        private AuditLogger $audit,
        private CandidatePhotoProcessor $photos,
    ) {}

    /**
     * Baris boleh berisi nama saja (nomor otomatis), "5. Nama", "5 - Nama", "5;Nama",
     * atau dua kolom Excel yang ditempel (nomor<TAB>nama).
     *
     * @return array<int, array{line: int, number: ?int, name: string}>
     */
    public function parse(string $text): array
    {
        $rows = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $index => $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $number = null;

            if (preg_match('/^(\d{1,3})\s*(?:[.)\-;,:|\t]|\s)\s*(.+)$/u', $line, $match) === 1) {
                $number = (int) $match[1];
                $line = $match[2];
            }

            $rows[] = ['line' => $index + 1, 'number' => $number, 'name' => Str::squish($line)];
        }

        return $rows;
    }

    /**
     * @return int jumlah calon yang ditambahkan
     */
    public function import(Ballot $ballot, ?int $unitId, string $text, User $actor): int
    {
        abort_unless($actor->isSuperAdmin(), 403);
        $this->assertEditable($ballot);

        $perUnit = $ballot->scope === BallotScope::PerRt;

        if ($perUnit && $unitId === null) {
            throw VotingException::invalidState('Pilih RT dulu: surat suara ini per RT.');
        }

        $unitId = $perUnit ? $unitId : null;
        $rows = $this->parse($text);

        if ($rows === []) {
            throw VotingException::invalidState('Belum ada nama. Tempel satu nama calon per baris.');
        }

        return DB::transaction(function () use ($ballot, $unitId, $rows): int {
            $existing = Candidate::query()
                ->where('ballot_id', $ballot->id)
                ->where('unit_id', $unitId)
                ->lockForUpdate()
                ->pluck('number')
                ->all();

            $used = array_flip($existing);
            $next = ($existing === [] ? 0 : max($existing)) + 1;
            $problems = [];
            $prepared = [];

            foreach ($rows as $row) {
                $number = $row['number'] ?? $next;

                while ($row['number'] === null && isset($used[$number])) {
                    $number++;
                }

                if ($row['name'] === '' || mb_strlen($row['name']) > 120) {
                    $problems[] = "baris {$row['line']}: nama kosong atau lebih dari 120 huruf";
                } elseif ($number < 1 || $number > 999) {
                    $problems[] = "baris {$row['line']}: nomor {$number} tidak valid";
                } elseif (isset($used[$number])) {
                    $problems[] = "baris {$row['line']}: nomor {$number} sudah dipakai";
                }

                $used[$number] = true;
                $next = max($next, $number + 1);
                $prepared[] = ['number' => $number, 'name' => $row['name']];
            }

            if ($ballot->max_candidates !== null && count($existing) + count($prepared) > $ballot->max_candidates) {
                $problems[] = "melebihi batas {$ballot->max_candidates} calon (sudah ada ".count($existing).', ditambah '.count($prepared).')';
            }

            if ($problems !== []) {
                throw VotingException::invalidState('Tidak ada yang disimpan. Perbaiki dulu: '.implode('; ', array_slice($problems, 0, 5)).(count($problems) > 5 ? '; …' : '').'.');
            }

            foreach ($prepared as $item) {
                $candidate = new Candidate(['number' => $item['number'], 'name' => $item['name'], 'status' => CandidateStatus::Aktif, 'unit_id' => $unitId]);
                $candidate->ballot()->associate($ballot);
                $candidate->save();

                $this->audit->log('candidate.created', $candidate, $ballot->election, meta: $candidate->only(['number', 'name', 'status']) + ['via' => 'bulk']);
            }

            return count($prepared);
        });
    }

    /**
     * Pasangkan foto ke calon berdasarkan angka di awal nama file (mis. "01.jpg", "2 - Siti.png").
     *
     * @param  array<string, string>  $files  path sementara (disk local) => nama file asli
     * @return array{matched: array<int, string>, skipped: array<int, string>}
     */
    public function attachPhotos(Ballot $ballot, ?int $unitId, array $files, User $actor): array
    {
        abort_unless($actor->isSuperAdmin(), 403);
        $this->assertEditable($ballot);

        $candidates = Candidate::query()
            ->where('ballot_id', $ballot->id)
            ->where('unit_id', $ballot->scope === BallotScope::PerRt ? $unitId : null)
            ->get()
            ->keyBy('number');

        $matched = [];
        $skipped = [];

        foreach ($files as $path => $originalName) {
            $number = preg_match('/^\s*0*(\d{1,3})(?!\d)/', (string) $originalName, $match) === 1 ? (int) $match[1] : null;
            $candidate = $number !== null ? $candidates->get($number) : null;

            if ($candidate === null) {
                Storage::disk('local')->delete($path);
                $skipped[] = "{$originalName} (tidak ada calon nomor ".($number ?? '?').')';

                continue;
            }

            try {
                $this->photos->replace($candidate, $path);
                $matched[] = "{$candidate->displayNumber()} {$candidate->name}";
            } catch (RuntimeException $exception) {
                $skipped[] = "{$originalName} ({$exception->getMessage()})";
            }
        }

        return ['matched' => $matched, 'skipped' => $skipped];
    }

    private function assertEditable(Ballot $ballot): void
    {
        if (! $ballot->election->status->allowsConfigurationChanges()) {
            throw VotingException::invalidState('Pemilihan sudah dimulai; calon tidak bisa diubah.');
        }
    }
}
