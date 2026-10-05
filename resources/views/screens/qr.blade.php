<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>QR · {{ $election->name }}</title>
    @vite(['resources/css/voter.css'])
</head>
<body>
    <header class="topbar">
        <p class="topbar__label">Pindai untuk memilih</p>
        <p class="topbar__title">{{ $election->name }}</p>
    </header>
    <main class="wrap center">
        <section class="card">
            <h1>Arahkan kamera HP ke kode ini</h1>
            <img class="qr" src="{{ $qr }}" alt="Kode QR halaman pemilih" width="520" height="520">
            <p class="muted">Atau ketik alamat:</p>
            <p class="qr__url">{{ $url }}</p>
        </section>
        <section class="card">
            <p><strong>Siapkan kertas PIN Anda.</strong> Halaman di HP akan berubah sendiri saat panitia membuka voting.</p>
        </section>
    </main>
</body>
</html>
