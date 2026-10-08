<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1f1d5c">
    <title>@yield('title') · {{ config('app.name') }}</title>
    @vite(['resources/css/portal.css', 'resources/js/portal.js'])
</head>
<body class="portal">
    @php($areaName = \App\Models\AppSetting::get(\App\Models\AppSetting::AREA_NAME, 'Portal pemilihan'))

    {{-- Header pita (seperti referensi): nama wilayah + nama portal, penanda pemilihan di kanan. --}}
    <header class="masthead">
        <div class="masthead__inner">
            <a class="masthead__brand" href="{{ route('public.index') }}">
                <span class="masthead__logo" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12.5l5 5L20 6.5"></path></svg>
                </span>
                <span>
                    <span class="masthead__area">{{ $areaName }}</span>
                    <span class="masthead__title">{{ config('app.name') }}</span>
                </span>
            </a>

            @if ($headline ?? null)
                <a class="headline headline--{{ $headline['tone'] }}" href="{{ $headline['url'] }}">
                    <span class="headline__label"><span class="headline__dot" aria-hidden="true"></span>{{ $headline['label'] }}</span>
                    <span class="headline__text">{{ $headline['text'] }}</span>
                </a>
            @endif
        </div>
    </header>

    {{-- Menu utama: garis bawah pada menu aktif; di HP bisa digeser ke samping. --}}
    <nav class="portal-nav" aria-label="Menu utama">
        <div class="portal-nav__inner">
            {{-- Satu halaman: menu meluncur ke bagiannya; di halaman detail, menu kembali ke bagian di beranda. --}}
            @php($onHome = request()->routeIs('public.index'))
            <div class="portal-nav__tabs" data-scrollspy>
                @foreach ([
                    ['beranda', 'Beranda', $onHome],
                    ['hasil', 'Hasil', request()->routeIs('public.show')],
                    ['calon', 'Calon', request()->routeIs('public.candidates')],
                    ['pemilihan', 'Pemilihan', false],
                    ['cara-memilih', 'Cara memilih', false],
                ] as [$section, $label, $active])
                    <a class="portal-nav__tab {{ $active ? 'is-active' : '' }}" href="{{ $onHome ? '#'.$section : route('public.index').'#'.$section }}" data-section="{{ $section }}" @if ($active) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </div>
            <a class="portal-nav__login" href="{{ url('/admin') }}" aria-label="Masuk panitia dan pengurus" title="Masuk panitia dan pengurus">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="4" y="10" width="16" height="11" rx="2"></rect>
                    <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                </svg>
                <span>Panitia</span>
            </a>
        </div>
    </nav>

    <main class="portal-main">
        @yield('content')
    </main>

    <footer class="portal-footer">
        <div class="portal-footer__inner">
            <div>
                <p class="portal-footer__title">{{ config('app.name') }}</p>
                <p>{{ $areaName }}</p>
            </div>
            <p class="portal-footer__note">Halaman ini hanya menampilkan informasi resmi dari panitia. Jumlah suara tidak ditampilkan di sini; rinciannya tercatat di berita acara.</p>
        </div>
    </footer>
</body>
</html>
