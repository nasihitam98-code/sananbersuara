@extends('voter.layout', ['page' => 'start'])

@section('content')
    <main class="wrap">
        @if (session('notice'))
            <div class="notice" role="status">{{ session('notice') }}</div>
        @endif

        @if ($status['state'] === 'open')
            @include('voter.partials.timer')

            <section class="card">
                <span class="step">Langkah 1 dari 3</span>
                <h1>Cari nama Anda</h1>
                <p class="muted">Ketik minimal 3 huruf nama Anda, lalu sentuh nama Anda di daftar.</p>

                <label for="search" class="sr-only">Nama Anda</label>
                <input
                    id="search"
                    class="input"
                    type="search"
                    inputmode="search"
                    autocomplete="off"
                    autocapitalize="words"
                    spellcheck="false"
                    maxlength="60"
                    placeholder="Contoh: Budi"
                    data-search-input
                    data-search-url="{{ route('voter.search', $election->access_code) }}"
                    data-pin-url="{{ route('voter.pin', [$election->access_code, '__ID__']) }}"
                >

                <p class="muted" data-search-hint>Minimal 3 huruf.</p>
                <p class="error hidden" data-search-empty role="status"></p>
                <ul class="results" data-search-results aria-live="polite"></ul>
            </section>
        @elseif ($status['state'] === 'paused')
            <section class="card center" role="status">
                <div class="pulse" aria-hidden="true"></div>
                <h1>Pemungutan dijeda sebentar</h1>
                <p class="muted">Mohon tunggu. Halaman ini akan berubah sendiri saat dilanjutkan.</p>
            </section>
        @elseif ($status['state'] === 'finished')
            <section class="card center" role="status">
                <h1>Pemungutan suara sudah selesai</h1>
                <p class="muted">Terima kasih atas partisipasi Anda.</p>
            </section>
        @else
            <section class="card center" role="status">
                <div class="pulse" aria-hidden="true"></div>
                <h1>Menunggu pemungutan dibuka</h1>
                <p class="muted">Jangan tutup halaman ini. Halaman akan berubah sendiri saat panitia membuka pemungutan suara.</p>
            </section>

            <section class="card">
                <h2>Siapkan:</h2>
                <p>Kertas berisi <strong>PIN 4 angka</strong> yang Anda terima dari petugas di pintu.</p>
                <p class="muted">PIN hanya untuk Anda. Jangan berikan kepada orang lain.</p>
                @include('voter.partials.privacy-notice')
            </section>

            @foreach ($thumbs as $thumb)
                <link rel="prefetch" href="{{ $thumb }}" as="image">
            @endforeach
        @endif
    </main>
@endsection
