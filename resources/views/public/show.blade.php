@extends('public.layout')

@section('title', $election->name)
@section('heading', $election->name)

@section('content')
    @if ($election->status === \App\Enums\ElectionStatus::Unpublished)
        <section class="card center" role="status">
            <h1>Hasil sedang ditinjau ulang</h1>
            <p class="muted">Panitia sedang memeriksa kembali hasil pemilihan ini. Silakan kembali lagi nanti.</p>
        </section>
    @else
        <section class="card public-intro">
            <h1>Hasil resmi</h1>
            <dl class="public-facts">
                @if ($election->started_at)
                    <div><dt>Dilaksanakan</dt><dd>{{ $election->started_at->translatedFormat('l, d F Y') }}</dd></div>
                @endif
                @if ($election->published_at)
                    <div><dt>Diumumkan</dt><dd>{{ $election->published_at->translatedFormat('d F Y, H:i') }}</dd></div>
                @endif
                <div><dt>Cara</dt><dd>{{ $election->isDadakan() ? 'Warga yang hadir memilih lewat HP (nama + PIN), satu orang satu suara.' : 'Pemilih terdaftar per RT memilih di bilik, satu orang satu suara.' }}</dd></div>
            </dl>
            <p class="muted">Yang ditampilkan adalah calon yang <strong>ditetapkan panitia</strong> berdasarkan hasil penghitungan suara dan berita acara yang telah disahkan. Rincian jumlah suara tercatat di berita acara.</p>
        </section>

        @if ($units->count() > 1)
            <nav class="tabs" aria-label="Pilih RT">
                @foreach ($units as $unit)
                    <a class="tabs__item {{ $unit->is($selectedUnit) ? 'tabs__item--active' : '' }}"
                       href="{{ route('public.show', [$election->public_id, 'rt' => $unit->code]) }}"
                       @if ($unit->is($selectedUnit)) aria-current="page" @endif>{{ $unit->name }}</a>
                @endforeach
            </nav>
        @endif

        @foreach ($rows as $row)
            <section class="card">
                <h2>{{ $row['title'] }}</h2>
                <span class="step">{{ $row['label'] }}</span>
                @if ($row['decided'])
                    <p class="muted public-meaning">
                        {{ $row['candidates']->count() === 1
                            ? 'Calon berikut ditetapkan sebagai yang terpilih.'
                            : 'Calon berikut ditetapkan lolos ke tahap berikutnya ('.$row['candidates']->count().' orang, urut nomor calon).' }}
                    </p>
                @endif

                @if ($row['decided'])
                    <div class="winners {{ $row['candidates']->count() === 1 ? 'winners--single' : '' }}">
                        @foreach ($row['candidates'] as $candidate)
                            <div class="winner">
                                <span class="photo winner__photo">
                                    @if ($candidate->photoUrl('large'))
                                        <img src="{{ $candidate->photoUrl('large') }}" alt="Foto {{ $candidate->name }}" width="480" height="480" loading="lazy">
                                    @else
                                        <span class="photo__initials" aria-hidden="true">{{ $candidate->initials() }}</span>
                                    @endif
                                    <span class="badge" aria-hidden="true">{{ $candidate->displayNumber() }}</span>
                                </span>
                                <p class="candidate__number">Nomor {{ $candidate->displayNumber() }}</p>
                                <p class="winner__name">{{ $candidate->name }}</p>
                                @if ($candidate->originLabel())
                                    <p class="candidate__origin">Asal {{ $candidate->originLabel() }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="muted">Hasil untuk bagian ini belum ditetapkan panitia.</p>
                @endif
            </section>
        @endforeach

        <p class="muted center">Hasil resmi berdasarkan berita acara yang telah disahkan panitia.</p>
    @endif

    <p class="center"><a href="{{ route('public.index') }}">← Semua hasil</a></p>
@endsection
