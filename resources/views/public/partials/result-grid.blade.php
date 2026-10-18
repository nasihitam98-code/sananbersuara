{{--
    Kartu hasil: jabatan semua RT (mis. Ketua RW) besar di atas, lalu kartu per RT (digeser bila banyak).
    Dipakai hasil resmi dan quick count. Parameter: $cards (semua kartu), $unitCards (kartu per RT).
--}}
@php($areaCards = $cards->filter(fn (array $card): bool => $card['unit'] === null)->values())

{{-- Jabatan untuk semua RT (mis. Ketua RW) berdiri sendiri di atas, kartu besar. --}}
@if ($areaCards->isNotEmpty())
    <div class="grid feature feature--{{ min(2, $areaCards->count()) }} reveal">
        @foreach ($areaCards as $card)
            @include('public.partials.result-card', ['card' => $card, 'wide' => true])
        @endforeach
    </div>
@endif

{{-- Kartu per RT: digeser bila banyak (seperti kartu hasil di contoh), lebar bila hanya 1–2. --}}
@if ($unitCards->count() > 0 && $unitCards->count() <= 2)
    <div class="grid feature feature--{{ $unitCards->count() }} subsection reveal">
        @foreach ($unitCards as $card)
            @include('public.partials.result-card', ['card' => $card, 'wide' => true])
        @endforeach
    </div>
@elseif ($unitCards->count() > 2)
    {{--
        Lebih dari 3 RT: halaman berisi 6 kartu (3 kolom × 2 baris, urut RT) yang digeser per halaman.
        Di HP halaman dilepas (display: contents) sehingga kartu digeser satu per satu.
    --}}
    @php($perPage = $unitCards->count() > 3 ? 6 : 1)
    {{--
        RT pilihan warga disematkan di bawah kartu RW (diingat di perangkatnya; link #rt-03 dari Admin RT juga
        menyematkannya). Diisi portal.js dari kartu di carousel; kosong/tersembunyi tanpa JavaScript.
    --}}
    <div class="rt-pinned subsection" data-rt-pinned hidden></div>
    {{-- Tombol per RT: lompat ke kartu RT itu sekaligus menyematkannya. --}}
    <nav class="rt-picker subsection reveal" data-rt-picker aria-label="Pilih RT Anda">
        <span class="rt-picker__label">Pilih RT Anda:</span>
        @foreach ($unitCards->values() as $index => $card)
            <button type="button" class="rt-picker__chip" data-rt-jump="{{ $index }}" data-rt-code="{{ $card['unit']->code }}">{{ $card['unit']->name }}</button>
        @endforeach
    </nav>
    <div class="carousel {{ $perPage > 1 ? 'carousel--pages' : '' }} reveal" data-carousel data-per-page="{{ $perPage }}">
        <button type="button" class="carousel__nav carousel__nav--prev" data-carousel-prev aria-label="Sebelumnya">‹</button>
        <div class="carousel__track" data-carousel-track tabindex="0" aria-label="Kartu hasil per RT">
            @foreach ($unitCards->chunk($perPage) as $page)
                <div class="carousel__slide {{ $perPage > 1 ? 'carousel__page' : '' }}" data-carousel-slide>
                    @foreach ($page as $card)
                        <div class="carousel__card" data-carousel-card data-rt="{{ $card['unit']->code }}">
                            @include('public.partials.result-card', ['card' => $card])
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
        <button type="button" class="carousel__nav carousel__nav--next" data-carousel-next aria-label="Berikutnya">›</button>
        <div class="carousel__foot">
            <div class="carousel__dots" data-carousel-dots></div>
            <span class="carousel__count" data-carousel-count></span>
        </div>
    </div>
@endif
