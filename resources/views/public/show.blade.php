@extends('public.layout')

@section('title', $election->name.($selectedUnit ? ' · '.$selectedUnit->name : ''))
@section('description', 'Hasil resmi '.$election->name.($selectedUnit ? ' untuk '.$selectedUnit->name : '').': yang terpilih dan perolehan suara tiap calon.')

@section('content')
    <section class="page-head reveal">
        <p class="section__kicker">Hasil pemilihan</p>
        <h1 class="page-head__title">{{ $election->name }}</h1>

        @if ($election->status !== \App\Enums\ElectionStatus::Unpublished)
            <dl class="facts">
                @if ($election->started_at)
                    <div><dt>Dilaksanakan</dt><dd>{{ $election->started_at->translatedFormat('l, d F Y') }}</dd></div>
                @endif
                @if ($election->published_at)
                    <div><dt>Diumumkan</dt><dd>{{ $election->published_at->translatedFormat('d F Y, H:i') }}</dd></div>
                @endif
                <div><dt>Cara</dt><dd>{{ $election->isDadakan() ? 'Warga yang hadir memilih lewat HP (nama + PIN), satu orang satu suara.' : 'Pemilih terdaftar per RT memilih di bilik, satu orang satu suara.' }}</dd></div>
            </dl>
        @endif
    </section>

    @if ($election->status === \App\Enums\ElectionStatus::Unpublished)
        <section class="empty reveal" role="status">
            <h2>Hasil sedang ditinjau ulang</h2>
            <p>Panitia sedang memeriksa kembali hasil pemilihan ini. Silakan kembali lagi nanti.</p>
        </section>
    @else
        <p class="lead reveal">Perolehan suara resmi tiap calon, dan calon yang <strong>ditetapkan panitia</strong> berdasarkan penghitungan suara dan berita acara yang telah disahkan.</p>

        {{-- Sama seperti beranda: jabatan semua RT (Ketua RW) kartu besar di atas, lalu pilih RT dan kartunya. --}}
        @foreach ($cards->where('unit', null) as $card)
            <div class="grid feature feature--1 reveal">
                @include('public.partials.result-card', ['card' => $card, 'wide' => true])
            </div>
        @endforeach

        {{-- Pilihan RT sebagai pil (seperti pilihan jenis pemilihan di referensi). --}}
        @if ($units->count() > 1)
            <nav class="pills reveal" aria-label="Pilih RT">
                @foreach ($units as $unit)
                    <a class="pill {{ $unit->is($selectedUnit) ? 'is-active' : '' }}"
                       href="{{ route('public.show', [$election->public_id, 'rt' => $unit->code]) }}"
                       @if ($unit->is($selectedUnit)) aria-current="page" @endif>{{ $unit->name }}</a>
                @endforeach
            </nav>
        @endif

        @if ($cards->whereNotNull('unit')->isNotEmpty())
            <div class="grid grid--results reveal">
                @foreach ($cards->whereNotNull('unit') as $card)
                    @include('public.partials.result-card', ['card' => $card])
                @endforeach
            </div>
        @endif
    @endif

    <p class="back-links">
        @if ($showElections)
            <a href="{{ route('public.index') }}#pemilihan">Semua pemilihan</a>
        @else
            <a href="{{ route('public.index') }}">Kembali ke beranda</a>
        @endif
    </p>
@endsection
