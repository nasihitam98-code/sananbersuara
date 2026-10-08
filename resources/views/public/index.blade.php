@extends('public.layout')

@section('title', 'Beranda')

@section('content')
    {{-- ============ BERANDA ============ --}}
    <section id="beranda" class="anchor">
        @if ($notice = \App\Models\AppSetting::get(\App\Models\AppSetting::PUBLIC_NOTICE))
            <div class="notice reveal" role="note">
                <span class="notice__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1z"></path><path d="M15.5 8.5a5 5 0 0 1 0 7"></path><path d="M18.5 5.5a9 9 0 0 1 0 13"></path></svg>
                </span>
                <div>
                    <h2 class="notice__title">Pengumuman</h2>
                    <p>{!! nl2br(e($notice)) !!}</p>
                </div>
            </div>
        @endif

        @forelse ($running as $election)
            <div class="hero reveal">
                <div class="hero__body">
                    <span class="chip {{ $election->status->isLive() ? 'chip--live' : 'chip--soon' }}">
                        <span class="chip__dot" aria-hidden="true"></span>{{ $election->status->isLive() ? 'Sedang berlangsung' : 'Segera dimulai' }}
                    </span>
                    <h1 class="hero__title">{{ $election->name }}</h1>
                    <p class="hero__text">
                        {{ $election->isDadakan()
                            ? 'Pemungutan suara di lokasi acara: warga yang hadir mendaftar di meja pintu, lalu memilih lewat HP dengan nama dan PIN.'
                            : 'Pemungutan suara di TPS masing-masing RT: pemilih terdaftar memilih di bilik suara.' }}
                        Cara memilih diumumkan langsung oleh panitia di lokasi.
                    </p>
                    <div class="hero__actions">
                        <a class="button button--light" href="#calon">Kenali calon &amp; visi-misi</a>
                        <a class="button button--ghost-light" href="#cara-memilih">Cara memilih</a>
                    </div>
                </div>
                <div class="hero__art" aria-hidden="true">
                    <svg viewBox="0 0 120 120" width="150" height="150" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"><rect x="20" y="52" width="80" height="50" rx="8"></rect><path d="M38 52V30a6 6 0 0 1 6-6h32a6 6 0 0 1 6 6v22"></path><path d="M48 40l8 8 16-16"></path><path d="M20 76h80"></path></svg>
                </div>
            </div>
        @empty
            <div class="hero hero--calm reveal">
                <div class="hero__body">
                    <span class="chip chip--done">{{ $featured ? 'Hasil resmi sudah diumumkan' : 'Portal pemilihan warga' }}</span>
                    <h1 class="hero__title">{{ $featured?->name ?? 'Informasi pemilihan warga' }}</h1>
                    <p class="hero__text">Informasi resmi pemilihan: calon, hasil yang ditetapkan panitia, partisipasi warga, dan cara memilih.</p>
                    <div class="hero__actions">
                        <a class="button button--light" href="#hasil">Lihat hasil</a>
                        <a class="button button--ghost-light" href="#cara-memilih">Cara memilih</a>
                    </div>
                </div>
                @if ($turnout && $turnout['total'] > 0)
                    <div class="hero__stat">
                        <span class="hero__stat-label">Partisipasi</span>
                        <span class="hero__stat-value"><span data-count-to="{{ $turnout['percent'] }}">{{ number_format($turnout['percent'], 1, ',', '') }}</span>%</span>
                        <span class="hero__stat-sub">{{ $turnout['voted'] }} dari {{ $turnout['total'] }} warga memilih</span>
                    </div>
                @endif
            </div>
        @endforelse
    </section>

    {{-- Hasil dan Calon: saat ada pemilihan yang akan/sedang berjalan, Calon didahulukan (urutan dari controller). --}}
    @foreach ($sections as $section)
        @if (in_array($section, ['hasil', 'calon'], true))
            @include('public.sections.'.$section)
        @endif
    @endforeach

    {{-- ============ PEMILIHAN ============ --}}
    <section id="pemilihan" class="anchor section" aria-labelledby="pemilihan-judul">
        <div class="section__head reveal">
            <div>
                <p class="section__kicker">Pemilihan</p>
                <h2 class="section__title" id="pemilihan-judul">Semua pemilihan</h2>
            </div>
        </div>

        @if ($running->isNotEmpty() || $announced->isNotEmpty())
            <div class="grid grid--3 reveal">
                @foreach ($running->concat($announced) as $election)
                    @include('public.partials.election-card', ['election' => $election])
                @endforeach
            </div>
        @else
            <div class="empty reveal">
                <p>Belum ada pemilihan yang diumumkan.</p>
            </div>
        @endif
    </section>

    {{-- ============ CARA MEMILIH ============ --}}
    <section id="cara-memilih" class="anchor section" aria-labelledby="cara-judul">
        <div class="section__head reveal">
            <div>
                <p class="section__kicker">Panduan</p>
                <h2 class="section__title" id="cara-judul">Cara memilih</h2>
            </div>
        </div>
        @include('public.partials.guide')
    </section>
@endsection
