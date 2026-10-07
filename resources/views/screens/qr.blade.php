<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>QR · {{ $election->name }}</title>
    @vite(['resources/css/voter.css', 'resources/js/screen.js'])
</head>
<body class="screen" data-status-url="{{ route('screens.qr.status', $election->public_id) }}" data-phase="{{ $status['phase'] }}">
    <header class="topbar screen__topbar">
        <div>
            <p class="topbar__label">Pindai untuk memilih</p>
            <p class="topbar__title">{{ $election->name }}</p>
        </div>
        <button type="button" class="screen__fullscreen" data-fullscreen>⛶ Layar penuh</button>
    </header>
    <main class="screen__grid">
        <section class="card screen__qr" data-qr-card>
            <h1>Arahkan kamera HP ke kode ini</h1>
            <img class="qr" src="{{ $qr }}" alt="Kode QR halaman pemilih" width="520" height="520">
            <p class="muted">Atau ketik alamat:</p>
            <p class="qr__url">{{ $url }}</p>
        </section>

        <section class="card screen__status" aria-live="polite">
            <p class="screen__wave" data-wave>{{ $status['wave_name'] ?? '' }}</p>
            <p class="screen__headline" data-headline>
                @switch($status['phase'])
                    @case('open') VOTING DIBUKA @break
                    @case('paused') Voting dijeda sebentar @break
                    @case('finished') Pemilihan selesai @break
                    @default Menunggu voting dibuka
                @endswitch
            </p>
            <p class="screen__timer {{ $status['remaining'] === null ? 'hidden' : '' }}" data-timer>--:--</p>

            <p class="screen__count"><strong data-voted>{{ $status['voted'] }}</strong> dari <span data-attendees>{{ $status['attendees'] }}</span> sudah memilih</p>
            <div class="screen__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $status['percent'] }}" data-bar>
                <div class="screen__bar-fill" data-bar-fill></div>
            </div>
            <p class="screen__percent"><span data-percent>{{ $status['percent'] }}</span>%</p>

            <p class="screen__hint" data-hint>Siapkan kertas PIN Anda. Halaman di HP berubah sendiri saat voting dibuka.</p>
        </section>
    </main>
</body>
</html>
