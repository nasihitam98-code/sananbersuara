<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Berita acara {{ $election->name }}</title>
    <link rel="icon" href="{{ \App\Models\AppSetting::faviconUrl() }}">
    @vite(['resources/css/report.css', 'resources/js/report.js'])
</head>
<body>
    {{-- Semua berita acara terbaru dalam satu halaman: sekali Cetak / Simpan sebagai PDF, tiap lingkup di lembar sendiri. --}}
    <div class="toolbar no-print">
        <button type="button" data-print>Cetak semua / Simpan sebagai PDF</button>
        <span class="watermark-note">{{ count($sheets) }} berita acara.
            @if ($drafts > 0) {{ $drafts }} masih draf (belum sah sampai disahkan Super Admin). @endif
        </span>
    </div>

    <main class="paper-stack">
        @forelse ($sheets as $sheet)
            @include('reports.partials.paper', $sheet)
        @empty
            <p class="no-print">Belum ada berita acara. Klik "Buat draf semua" di halaman Verifikasi &amp; Publikasi.</p>
        @endforelse
    </main>
</body>
</html>
