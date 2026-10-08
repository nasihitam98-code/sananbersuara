{{-- Kartu hasil satu jabatan/RT: siapa yang ditetapkan panitia, dengan cap "Terpilih"/"Lolos". Tanpa angka suara. --}}
@php($count = $card['candidates']->count())
<article class="race {{ $card['decided'] ? '' : 'race--pending' }} {{ $count === 1 ? 'race--single' : '' }} {{ $count > 2 ? 'race--many' : '' }} {{ ($wide ?? false) ? 'race--wide' : '' }}">
    <header class="race__head">
        <div>
            <p class="race__office">{{ $card['unit'] !== null ? $card['office'] : 'Semua RT' }}</p>
            <h3 class="race__title">{{ $card['unit']?->name ?? $card['office'] }}</h3>
        </div>
        @if ($card['decided'])
            <span class="stamp">{{ $card['label'] }}</span>
        @else
            <span class="chip chip--muted">Belum ditetapkan</span>
        @endif
    </header>

    @if ($card['decided'])
        <div class="race__people">
            @foreach ($card['candidates'] as $candidate)
                <div class="person">
                    @include('public.partials.portrait', ['candidate' => $candidate, 'class' => 'portrait--winner'])
                    <p class="person__name">{{ $candidate->name }}</p>
                    <p class="person__meta">Nomor {{ $candidate->displayNumber() }}@if ($candidate->originLabel()) · Asal {{ $candidate->originLabel() }}@endif</p>
                </div>
            @endforeach
        </div>
        <p class="race__meaning">
            {{ $count === 1 ? 'Ditetapkan sebagai yang terpilih.' : 'Ditetapkan lolos ke tahap berikutnya ('.$count.' orang, urut nomor calon).' }}
        </p>
    @else
        <p class="race__empty">Hasil untuk bagian ini belum ditetapkan panitia.</p>
    @endif
</article>
