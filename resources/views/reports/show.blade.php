<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $report->number }}</title>
    <link rel="icon" href="{{ \App\Models\AppSetting::faviconUrl() }}">
    @vite(['resources/css/report.css', 'resources/js/report.js'])
</head>
<body>
    <div class="toolbar no-print">
        <button type="button" data-print>Cetak / Simpan sebagai PDF</button>
        @if ($report->status->value !== 'DISAHKAN')
            <span class="watermark-note">Status: {{ $report->status->getLabel() }}. Belum sah sampai disahkan Super Admin.</span>
        @endif
    </div>

    <main>
        @include('reports.partials.paper', ['report' => $report, 'data' => $data, 'checksumValid' => $checksumValid])
    </main>
</body>
</html>
