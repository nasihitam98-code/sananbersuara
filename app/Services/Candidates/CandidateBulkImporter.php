<?php

namespace App\Services\Candidates;

use App\Enums\BallotScope;
use App\Enums\CandidateStatus;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CandidatePhotoProcessor;
use App\Services\Voting\VotingException;
use Illuminate\Support\Collection;
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
     * Baris boleh berisi nama saja (nomor otomatis), "5. Nama", "5 - Nama", atau kolom yang
     * dipisah TAB (tempelan Excel), ";" atau "|": nomor | nama | RT, nama | RT, nomor | nama.
     * RT boleh ditulis "RT 03", "03", atau "3".
     *
     * @return array<int, array{line: int, number: ?int, name: string, rt: ?string}>
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
            $rt = null;
            $columns = array_values(array_filter(array_map('trim', preg_split('/\t|;|\|/u', $line) ?: []), fn (string $column): bool => $column !== ''));

            if (count($columns) >= 2) {
                if (ctype_digit($columns[0])) {
                    $number = (int) array_shift($columns);
                }

                $line = $columns[0] ?? '';
                $rt = $columns[1] ?? null;
            } elseif (preg_match('/^(\d{1,3})\s*(?:[.)\-,:]|\s)\s*(.+)$/u', $line, $match) === 1) {
                $number = (int) $match[1];
                $line = $match[2];
            }

            $rows[] = ['line' => $index + 1, 'number' => $number, 'name' => Str::squish($line), 'rt' => $rt];
        }

        return $rows;
    }

    /**
     * "RT 03" / "03" / "3" → id RT, null bila tidak dikenal.
     *
     * @param  Collection<int, Unit>  $units
     */
    private function resolveUnitId(string $value, Collection $units): ?int
    {
        if (preg_match('/(\d{1,3})/', $value, $match) !== 1) {
            return null;
        }

        return $units->first(fn (Unit $unit): bool => (int) $unit->code === (int) $match[1])?->id;
    }

    /**
     * @param  ?int  $defaultOriginUnitId  asal RT untuk baris yang tidak menulis RT sendiri
     * @return int jumlah calon yang ditambahkan
     */
    public function import(Ballot $ballot, ?int $unitId, string $text, User $actor, ?int $defaultOriginUnitId = null): int
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

        $units = Unit::query()->get(['id', 'code']);

        return DB::transaction(function () use ($ballot, $unitId, $rows, $perUnit, $units, $defaultOriginUnitId): int {
            $current = Candidate::query()
                ->where('ballot_id', $ballot->id)
                ->where('unit_id', $unitId)
                ->lockForUpdate()
                ->get(['number', 'name']);

            $existing = $current->pluck('number')->all();
            $used = array_flip($existing);
            // Inti nama (tanpa sapaan/gelar) → keterangan untuk pesan kembar.
            $names = $current->mapWithKeys(fn (Candidate $candidate): array => [
                Candidate::coreName($candidate->name) => "sama/mirip dengan \"{$candidate->name}\" (nomor {$candidate->displayNumber()})",
            ])->all();
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
                } elseif (isset($names[Candidate::coreName($row['name'])])) {
                    $problems[] = "baris {$row['line']}: nama \"{$row['name']}\" ".$names[Candidate::coreName($row['name'])];
                }

                // Asal RT hanya untuk surat suara yang bukan per RT (mis. calon RW).
                $originUnitId = $perUnit ? null : $defaultOriginUnitId;

                if ($row['rt'] !== null && ! $perUnit) {
                    $originUnitId = $this->resolveUnitId($row['rt'], $units);

                    if ($originUnitId === null) {
                        $problems[] = "baris {$row['line']}: RT \"{$row['rt']}\" tidak dikenal";
                    }
                }

                $names[Candidate::coreName($row['name'])] ??= "sama/mirip dengan baris {$row['line']}";
                $used[$number] = true;
                $next = max($next, $number + 1);
                $prepared[] = ['number' => $number, 'name' => $row['name'], 'origin_unit_id' => $originUnitId];
            }

            if ($ballot->max_candidates !== null && count($existing) + count($prepared) > $ballot->max_candidates) {
                $problems[] = "melebihi batas {$ballot->max_candidates} calon (sudah ada ".count($existing).', ditambah '.count($prepared).')';
            }

            if ($problems !== []) {
                throw VotingException::invalidState('Tidak ada yang disimpan. Perbaiki dulu: '.implode('; ', array_slice($problems, 0, 5)).(count($problems) > 5 ? '; …' : '').'.');
            }

            foreach ($prepared as $item) {
                $candidate = new Candidate([
                    'number' => $item['number'],
                    'name' => $item['name'],
                    'status' => CandidateStatus::Aktif,
                    'unit_id' => $unitId,
                    'origin_unit_id' => $item['origin_unit_id'],
                ]);
                $candidate->ballot()->associate($ballot);
                $candidate->save();

                $this->audit->log('candidate.created', $candidate, $ballot->election, meta: $candidate->only(['number', 'name', 'status', 'origin_unit_id']) + ['via' => 'bulk']);
            }

            return count($prepared);
        });
    }

    /**
     * Pasangkan foto ke calon berdasarkan nomor di awal nama file (mis. "01.jpg", "2 - Siti.png")
     * atau nama calon di nama file (mis. "Ibu Sumiati.jpg").
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
            [$candidate, $reason] = $this->matchPhoto((string) $originalName, $candidates);

            if ($candidate === null) {
                Storage::disk('local')->delete($path);
                $skipped[] = "{$originalName} ({$reason})";

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

    /**
     * Cocokkan satu file foto: nomor di depan nama file dulu, lalu nama calon di nama file
     * (huruf besar/kecil dan tanda baca diabaikan). Nama yang cocok ke lebih dari satu calon dilewati.
     *
     * @param  Collection<int, Candidate>  $candidates  calon dengan kunci nomor urut
     * @return array{0: ?Candidate, 1: string}
     */
    private function matchPhoto(string $originalName, Collection $candidates): array
    {
        if (preg_match('/^\s*0*(\d{1,3})(?!\d)/', $originalName, $match) === 1) {
            $candidate = $candidates->get((int) $match[1]);

            return [$candidate, $candidate === null ? "tidak ada calon nomor {$match[1]}" : ''];
        }

        $fileName = Candidate::normalizeName(pathinfo($originalName, PATHINFO_FILENAME));

        if (mb_strlen($fileName) < 3) {
            return [null, 'nama file tidak berisi nomor atau nama calon'];
        }

        $exact = $candidates->filter(fn (Candidate $candidate): bool => Candidate::normalizeName($candidate->name) === $fileName);
        $found = $exact->isNotEmpty() ? $exact : $candidates->filter(function (Candidate $candidate) use ($fileName): bool {
            $name = Candidate::normalizeName($candidate->name);

            // "Bapak Sutrisno 2026.jpg" atau cukup "Sutrisno.jpg" untuk calon "Bapak Sutrisno".
            return str_contains($fileName, $name) || str_contains($name, $fileName);
        });

        return match ($found->count()) {
            1 => [$found->first(), ''],
            0 => [null, 'tidak ada calon dengan nama ini'],
            default => [null, 'cocok dengan lebih dari satu calon; beri nomor di nama file'],
        };
    }

    private function assertEditable(Ballot $ballot): void
    {
        if (! $ballot->election->status->allowsConfigurationChanges()) {
            throw VotingException::invalidState('Pemilihan sudah dimulai; calon tidak bisa diubah.');
        }
    }
}
