<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1f1d5c">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ \App\Models\AppSetting::faviconUrl() }}">
    {{-- Pratinjau link saat dibagikan (WhatsApp, Telegram, dll.). --}}
    <meta name="description" content="@yield('description', 'Pengumuman resmi pemilihan warga dari panitia.')">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="id_ID">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="@yield('title') · {{ config('app.name') }}">
    <meta property="og:description" content="@yield('description', 'Pengumuman resmi pemilihan warga dari panitia.')">
    <meta property="og:url" content="{{ url()->current() }}">
    @vite(['resources/css/portal.css', 'resources/js/portal.js'])
</head>
<body class="portal">
    @php($areaName = \App\Models\AppSetting::get(\App\Models\AppSetting::AREA_NAME, 'Portal pemilihan'))

    {{-- Header pita (seperti referensi): nama wilayah + nama portal, penanda pemilihan di kanan. --}}
    <header class="masthead">
        <div class="masthead__inner">
            <a class="masthead__brand" href="{{ route('public.index') }}">
                {{-- Logo dari Pengaturan Tampilan, atau kotak merah bercentang bawaan. --}}
                @if ($logo = \App\Models\AppSetting::logoUrl())
                    <span class="masthead__logo masthead__logo--image" aria-hidden="true"><img src="{{ $logo }}" alt="" width="40" height="40"></span>
                @else
                    <span class="masthead__logo" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12.5l5 5L20 6.5"></path></svg>
                    </span>
                @endif
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
                @php($labels = ['beranda' => 'Beranda', 'calon' => 'Calon', 'hasil' => 'Hasil', 'pemilihan' => 'Pemilihan', 'cara-memilih' => 'Cara memilih'])
                @php($hidden = array_filter(['calon' => empty($showcase), 'pemilihan' => ! ($showElections ?? true), 'cara-memilih' => ! ($showGuide ?? true)]))
                @foreach (array_diff_key($labels, $hidden) as $section => $label)
                    @php($active = match ($section) {
                        'beranda' => $onHome,
                        'hasil' => request()->routeIs('public.show'),
                        default => false,
                    })
                    <a class="portal-nav__tab {{ $active ? 'is-active' : '' }}" href="{{ $onHome ? '#'.$section : route('public.index').'#'.$section }}" data-section="{{ $section }}" @if ($active) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </div>
            {{-- Layar penuh untuk laptop/TV di TPS (portal.js); tersembunyi bila browser tidak mendukung. --}}
            <button type="button" class="portal-nav__login portal-nav__fullscreen" data-fullscreen hidden aria-label="Layar penuh" title="Layar penuh (tekan Esc untuk keluar)">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 9V4h5"></path><path d="M20 9V4h-5"></path><path d="M4 15v5h5"></path><path d="M20 15v5h-5"></path>
                </svg>
                <span data-fullscreen-label>Layar penuh</span>
            </button>
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
            <p class="portal-footer__note">Halaman ini hanya menampilkan informasi resmi dari panitia. {{ ($quick ?? null) ? 'Angka quick count bersifat sementara; hasil resmi tampil setelah ditetapkan dan diumumkan, sesuai berita acara yang disahkan.' : 'Angka suara baru tampil setelah hasil ditetapkan dan diumumkan, sesuai berita acara yang disahkan.' }}</p>
        </div>
    </footer>
</body>
</html>
