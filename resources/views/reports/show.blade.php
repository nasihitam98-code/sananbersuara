<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $report->number }}</title>
    @vite(['resources/css/report.css', 'resources/js/report.js'])
</head>
<body>
    <div class="toolbar no-print">
        <button type="button" data-print>Cetak / Simpan sebagai PDF</button>
        @if ($report->status->value !== 'DISAHKAN')
            <span class="watermark-note">Status: {{ $report->status->getLabel() }}. Belum sah sampai disahkan Super Admin.</span>
        @endif
    </div>

    <main class="paper {{ $report->status->value === 'DISAHKAN' ? '' : 'paper--draft' }}">
        <header class="paper__header">
            <h1>BERITA ACARA HASIL PEMUNGUTAN SUARA</h1>
            <p class="paper__title">{{ $data['election']['name'] }}</p>
            <p>Lingkup: <strong>{{ $data['scope'] }}</strong> · Nomor: <strong>{{ $report->number }}</strong></p>
        </header>

        <section>
            <h2>A. Keterangan pemilihan</h2>
            <table class="kv">
                <tr><th>Mode</th><td>{{ $data['election']['mode'] }}</td></tr>
                <tr><th>Mulai</th><td>{{ $data['election']['started_at'] ?? '-' }}</td></tr>
                <tr><th>Selesai</th><td>{{ $data['election']['closed_at'] ?? '-' }}</td></tr>
            </table>
        </section>

        <section>
            <h2>B. Partisipasi</h2>
            @php($p = $data['participation'])
            <table class="kv">
                <tr><th>Peserta hadir terdata</th><td>{{ $p['registered'] }}</td></tr>
                <tr><th>Peserta yang memilih</th><td>{{ $p['voted'] }} ({{ $p['percent'] }}%)</td></tr>
                <tr><th>Memilih dengan bantuan panitia</th><td>{{ $p['assisted'] }}</td></tr>
                <tr><th>Datang terlambat (didata setelah voting dibuka)</th><td>{{ $p['late'] }}</td></tr>
                <tr>
                    <th>Rekonsiliasi hitung kepala</th>
                    <td>
                        @if ($p['headcount'] === null)
                            Tidak diinput
                        @else
                            Hitung kepala {{ $p['headcount'] }} · terdata {{ $p['registered'] }}
                            {{ (int) $p['headcount'] === (int) $p['registered'] ? '(cocok)' : '(SELISIH '.abs((int) $p['headcount'] - (int) $p['registered']).')' }}
                        @endif
                    </td>
                </tr>
            </table>
        </section>

        <section>
            <h2>C. Hasil per surat suara</h2>
            @foreach ($data['ballots'] as $ballot)
                <h3>{{ $ballot['title'] }}{{ $ballot['round'] ? ' · Putaran '.$ballot['round'] : '' }}</h3>
                <p>Suara sah: <strong>{{ $ballot['valid'] }}</strong> · Suara dibatalkan: <strong>{{ $ballot['cancelled'] }}</strong>
                    @if ($ballot['tie_at_top']) · <strong>Seri di peringkat teratas</strong> @endif
                </p>
                <table class="grid">
                    <thead><tr><th>Peringkat</th><th>No.</th><th>Nama calon</th><th>Suara</th><th>%</th></tr></thead>
                    <tbody>
                        @foreach ($ballot['candidates'] as $row)
                            <tr>
                                <td>{{ $row['rank'] }}</td>
                                <td>{{ $row['number'] }}</td>
                                <td>{{ $row['name'] }}{{ $row['withdrawn'] ? ' (mengundurkan diri)' : '' }}</td>
                                <td class="num">{{ $row['votes'] }}</td>
                                <td class="num">{{ $row['percent'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="outcome">
                    Penetapan panitia:
                    @if ($ballot['outcome'])
                        <strong>{{ $ballot['outcome']['label'] }}</strong>
                        {{ implode(', ', $ballot['outcome']['candidates']) }}
                        @if ($ballot['outcome']['note']) ({{ $ballot['outcome']['note'] }}) @endif
                    @else
                        <em>belum ditetapkan</em>
                    @endif
                </p>
            @endforeach
        </section>

        <section>
            <h2>D. Catatan kejadian</h2>
            <table class="kv">
                @foreach ($data['incidents'] as $label => $count)
                    <tr><th>{{ $label }}</th><td>{{ $count }}</td></tr>
                @endforeach
            </table>
            <p class="lines">Catatan lain: ................................................................................................................................</p>
            <p class="lines">..............................................................................................................................................</p>
        </section>

        <section class="signatures">
            <h2>E. Tanda tangan</h2>
            <div class="sign-grid">
                @foreach (['Ketua Panitia', 'Sekretaris Panitia', 'Saksi', 'Saksi'] as $role)
                    <div class="sign">
                        <p>{{ $role }}</p>
                        <div class="sign__space"></div>
                        <p>(..................................................)</p>
                    </div>
                @endforeach
            </div>
        </section>

        <footer class="paper__footer">
            Dibuat {{ $data['generated_at'] }} oleh {{ $report->creator?->name ?? '-' }}
            · Status: {{ $report->status->getLabel() }}
            @if ($report->ratified_at) · Disahkan {{ $report->ratified_at->format('d-m-Y H:i') }} oleh {{ $report->ratifier?->name }} @endif
            <br>Checksum SHA-256: <code>{{ $report->checksum }}</code> {{ $checksumValid ? '(valid)' : '(TIDAK VALID)' }}
            <br>Berita acara ini tidak memuat siapa memilih siapa.
        </footer>
    </main>
</body>
</html>
