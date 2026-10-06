<?php

namespace App\Services\Voters;

use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Voting\VotingException;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Import pemilih dari Excel/CSV (bagian 5.8): baca -> validasi per baris -> pratinjau -> konfirmasi -> simpan.
 * Tidak ada data yang tersimpan sebelum pengguna menekan konfirmasi.
 */
class VoterImport
{
    public const HEADERS = ['nama', 'rt', 'alamat', 'jenis_kelamin', 'tanggal_lahir', 'nik', 'no_hp'];

    public const MAX_ROWS = 5000;

    public function __construct(
        private VoterRegistry $registry,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array<int, array{line: int, name: string, unit: string, address: string, data: array<string, mixed>, unit_id: ?int, nik: ?string, errors: array<int, string>, warnings: array<int, string>}>
     */
    public function preview(string $absolutePath, string $extension, User $actor): array
    {
        $rows = $this->read($absolutePath, $extension);

        if ($rows === []) {
            throw VotingException::invalidState('File kosong atau baris judul tidak sesuai template.');
        }

        if (count($rows) > self::MAX_ROWS) {
            throw VotingException::invalidState('Maksimal '.self::MAX_ROWS.' baris per file.');
        }

        $units = Unit::query()->get()->keyBy(fn (Unit $unit): string => ltrim($unit->code, '0') ?: '0');
        $seenNik = [];
        $seenIdentity = [];
        $result = [];

        foreach ($rows as $line => $values) {
            $errors = [];
            $warnings = [];

            $name = $this->clean($values['nama'] ?? '');
            $address = $this->clean($values['alamat'] ?? '');
            $unitCode = ltrim(preg_replace('/\D/', '', (string) ($values['rt'] ?? '')), '0') ?: '';
            $unit = $units->get($unitCode);
            $gender = Str::upper(Str::substr(trim((string) ($values['jenis_kelamin'] ?? '')), 0, 1)) ?: null;
            $birthDate = $this->parseDate($values['tanggal_lahir'] ?? null);
            $nik = $this->digits($values['nik'] ?? null);
            $phone = $this->digits($values['no_hp'] ?? null);

            if (mb_strlen($name) < 3) {
                $errors[] = 'Nama kosong/terlalu pendek';
            }

            if ($unit === null) {
                $errors[] = 'RT tidak dikenal';
            } elseif (! $actor->canManageVotersOf($unit->id)) {
                $errors[] = 'Anda hanya boleh mengimpor pemilih RT sendiri';
            }

            if ($address === '') {
                $errors[] = 'Alamat kosong';
            }

            if ($gender !== null && ! in_array($gender, ['L', 'P'], true)) {
                $errors[] = 'Jenis kelamin harus L atau P';
            }

            if (filled($values['tanggal_lahir'] ?? null) && $birthDate === null) {
                $errors[] = 'Tanggal lahir tidak terbaca (pakai format 31-12-1970)';
            }

            if ($nik !== null && strlen($nik) !== 16) {
                $errors[] = 'NIK harus 16 digit';
            }

            if ($phone !== null && (strlen($phone) < 8 || strlen($phone) > 15)) {
                $errors[] = 'No. HP tidak valid';
            }

            if ($nik !== null) {
                if (isset($seenNik[$nik])) {
                    $errors[] = "NIK sama dengan baris {$seenNik[$nik]}";
                }

                $seenNik[$nik] = $line;
            }

            $identityKey = Str::lower($name).'|'.($birthDate ?? '');

            if ($birthDate !== null && isset($seenIdentity[$identityKey])) {
                $errors[] = "Nama + tanggal lahir sama dengan baris {$seenIdentity[$identityKey]}";
            }

            $seenIdentity[$identityKey] = $line;

            $data = ['name' => $name, 'address' => $address, 'gender' => $gender, 'birth_date' => $birthDate, 'phone' => $phone];

            if ($errors === [] && $unit !== null) {
                $check = $this->registry->checkDuplicates($data, $unit->id, $nik);

                if ($check['blocked'] !== null) {
                    $errors[] = $check['blocked'];
                } elseif ($check['similar'] !== []) {
                    $warnings[] = 'Nama sama sudah terdaftar: '.implode('; ', $check['similar']);
                }
            }

            $result[] = [
                'line' => $line,
                'name' => $name,
                'unit' => $unit?->name ?? (string) ($values['rt'] ?? ''),
                'address' => $address,
                'data' => $data,
                'unit_id' => $unit?->id,
                'nik' => $nik,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        return $result;
    }

    /**
     * Menyimpan baris valid. Baris dengan peringatan nama sama hanya disimpan jika dikonfirmasi.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{imported: int, skipped: int}
     */
    public function import(array $rows, User $actor, bool $includeWarnings): array
    {
        if ($this->registry->liveResmiElection() !== null) {
            throw VotingException::invalidState('Pemilihan sedang berlangsung: import dibekukan. Gunakan Tambah Pemilih Darurat.');
        }

        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if ($row['errors'] !== [] || ($row['warnings'] !== [] && ! $includeWarnings)) {
                $skipped++;

                continue;
            }

            try {
                $this->registry->create($row['data'], (int) $row['unit_id'], $actor, $row['nik'], confirmedDifferentPerson: $includeWarnings);
                $imported++;
            } catch (VotingException|DuplicateNameWarning) {
                $skipped++;
            }
        }

        $this->audit->log('voter.imported', meta: ['imported' => $imported, 'skipped' => $skipped], actor: $actor);

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @return array<int, array<string, mixed>> nomor baris file => nilai per kolom template
     */
    private function read(string $path, string $extension): array
    {
        $reader = Str::lower($extension) === 'xlsx' ? new XlsxReader : new CsvReader($this->csvOptions($path));
        $rows = [];

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                $headers = null;
                $line = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $line++;
                    $cells = $row->toArray();

                    if ($headers === null) {
                        $headers = array_map(fn ($cell): string => Str::of((string) $cell)->lower()->replace([' ', '.'], '_')->trim('_')->toString(), $cells);

                        if (! in_array('nama', $headers, true)) {
                            return [];
                        }

                        continue;
                    }

                    if (collect($cells)->filter(fn ($cell): bool => filled($cell))->isEmpty()) {
                        continue;
                    }

                    $values = [];

                    foreach ($headers as $index => $header) {
                        if (in_array($header, self::HEADERS, true)) {
                            $values[$header] = $cells[$index] ?? null;
                        }
                    }

                    $rows[$line] = $values;
                }

                break;
            }
        } catch (Throwable) {
            throw VotingException::invalidState('File tidak bisa dibaca. Gunakan template Excel (.xlsx) atau CSV.');
        } finally {
            $reader->close();
        }

        return $rows;
    }

    private function csvOptions(string $path): CsvOptions
    {
        $options = new CsvOptions;
        $handle = fopen($path, 'r');
        $firstLine = $handle === false ? '' : (string) fgets($handle);

        if ($handle !== false) {
            fclose($handle);
        }

        // Excel berbahasa Indonesia biasanya menyimpan CSV dengan titik-koma.
        $options->FIELD_DELIMITER = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        return $options;
    }

    /**
     * Membersihkan teks, termasuk karakter pemicu rumus spreadsheet di awal sel.
     */
    private function clean(mixed $value): string
    {
        return Str::of((string) $value)->squish()->ltrim('=+@')->trim()->limit(190, '')->toString();
    }

    private function digits(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', is_float($value) ? number_format($value, 0, '', '') : (string) $value);

        return $digits === '' ? null : $digits;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d');
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (['d-m-Y', 'd/m/Y', 'Y-m-d', 'd.m.Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);

                if ($date !== false && $date->format($format) === $value && $date->year > 1900 && $date->isPast()) {
                    return $date->format('Y-m-d');
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
