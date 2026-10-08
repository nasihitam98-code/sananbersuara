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

    {{-- ============ HASIL ============ --}}
    <section id="hasil" class="anchor section" aria-labelledby="hasil-judul">
        <div class="section__head reveal">
            <div>
                <p class="section__kicker">Hasil resmi</p>
                <h2 class="section__title" id="hasil-judul">{{ $featured?->name ?? 'Hasil pemilihan' }}</h2>
            </div>
            @if ($featured)
                <a class="section__link" href="{{ route('public.show', $featured->public_id) }}">Hasil lengkap per RT →</a>
            @endif
        </div>

        @if ($featured)
            {{-- Kartu per jabatan/RT: digeser bila banyak (seperti kartu hasil di referensi), lebar bila hanya 1–2. --}}
            @if ($cards->count() <= 2)
                <div class="grid feature feature--{{ $cards->count() }} reveal">
                    @foreach ($cards as $card)
                        @include('public.partials.result-card', ['card' => $card, 'wide' => true])
                    @endforeach
                </div>
            @else
                <div class="carousel reveal" data-carousel>
                    <button type="button" class="carousel__nav carousel__nav--prev" data-carousel-prev aria-label="Sebelumnya">‹</button>
                    <div class="carousel__track" data-carousel-track tabindex="0" aria-label="Kartu hasil">
                        @foreach ($cards as $card)
                            <div class="carousel__slide" data-carousel-slide>
                                @include('public.partials.result-card', ['card' => $card])
                            </div>
                        @endforeach
                    </div>
                    <button type="button" class="carousel__nav carousel__nav--next" data-carousel-next aria-label="Berikutnya">›</button>
                    <div class="carousel__foot">
                        <div class="carousel__dots" data-carousel-dots></div>
                        <span class="carousel__count" data-carousel-count>1 / {{ $cards->count() }}</span>
                    </div>
                </div>
            @endif

            {{-- Peta RT (pengganti peta wilayah di referensi): pilih RT, panel kanan menampilkan yang terpilih. --}}
            @if ($unitCards->count() > 1)
                <div class="subsection reveal">
                    <h3 class="subsection__title">Peta hasil RT</h3>
                    <div class="unit-map" data-unit-map>
                        <div class="unit-map__tiles" role="tablist" aria-label="Pilih RT">
                            @foreach ($unitCards as $index => $card)
                                <a class="tile {{ $index === 0 ? 'is-active' : '' }} {{ $card['decided'] ? '' : 'tile--pending' }}"
                                   href="{{ route('public.show', [$featured->public_id, 'rt' => $card['unit']->code]) }}"
                                   role="tab" aria-selected="{{ $index === 0 ? 'true' : 'false' }}"
                                   data-unit-tile="{{ $card['unit']->code }}">
                                    <span class="tile__name">{{ $card['unit']->name }}</span>
                                    <span class="tile__who">{{ $card['decided'] ? $card['candidates']->pluck('name')->implode(', ') : 'Belum ditetapkan' }}</span>
                                </a>
                            @endforeach
                        </div>
                        <div class="unit-map__panel">
                            @foreach ($unitCards as $index => $card)
                                <div data-unit-panel="{{ $card['unit']->code }}" role="tabpanel" @if ($index !== 0) hidden @endif>
                                    @include('public.partials.result-card', ['card' => $card])
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            {{-- Partisipasi warga (bukan suara calon): lingkaran keseluruhan + batang per RT. --}}
            @if ($turnout && $turnout['total'] > 0)
                <div class="subsection reveal">
                    <h3 class="subsection__title">Partisipasi warga</h3>
                    <div class="turnout">
                        <div class="gauge">
                            <svg class="gauge__svg" viewBox="0 0 140 140" aria-hidden="true">
                                <circle class="gauge__track" cx="70" cy="70" r="56" pathLength="100"></circle>
                                <circle class="gauge__value" cx="70" cy="70" r="56" pathLength="100" stroke-dasharray="{{ $turnout['percent'] }} 100"></circle>
                            </svg>
                            <div class="gauge__center">
                                <span class="gauge__number"><span data-count-to="{{ $turnout['percent'] }}">{{ number_format($turnout['percent'], 1, ',', '') }}</span>%</span>
                                <span class="gauge__label">memilih</span>
                            </div>
                            <p class="gauge__caption"><strong>{{ $turnout['voted'] }}</strong> dari <strong>{{ $turnout['total'] }}</strong> {{ $featured->isDadakan() ? 'warga hadir' : 'pemilih terdaftar' }}</p>
                        </div>

                        @if (count($turnout['units']) > 1)
                            <div class="turnout__chart">
                                <p class="turnout__note">Persentase warga yang memilih di setiap RT. Batang merah = partisipasi tertinggi.</p>
                                @include('public.partials.bar-chart', [
                                    'label' => 'Partisipasi per RT',
                                    'bars' => collect($turnout['units'])->map(fn (array $unit): array => ['label' => $unit['unit'], 'value' => $unit['percent'], 'detail' => $unit['voted'].'/'.$unit['total']])->all(),
                                ])
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            @if (count($history) > 1)
                <div class="subsection reveal">
                    <h3 class="subsection__title">Riwayat partisipasi</h3>
                    <div class="card-box">
                        @include('public.partials.bar-chart', [
                            'label' => 'Riwayat partisipasi',
                            'slot' => 130,
                            'bars' => collect($history)->map(fn (array $item): array => ['label' => $item['date'] ?? '-', 'value' => $item['percent'], 'detail' => \Illuminate\Support\Str::limit($item['name'], 18)])->all(),
                        ])
                    </div>
                </div>
            @endif
        @else
            <div class="empty reveal">
                <h3>Belum ada hasil yang diumumkan</h3>
                <p>Hasil resmi tampil di sini setelah panitia memeriksa, menetapkan, dan mengumumkannya.</p>
            </div>
        @endif
    </section>

    {{-- ============ CALON ============ --}}
    <section id="calon" class="anchor section" aria-labelledby="calon-judul">
        <div class="section__head reveal">
            <div>
                <p class="section__kicker">Kenali calon</p>
                <h2 class="section__title" id="calon-judul">{{ $focus?->name ?? 'Calon' }}</h2>
            </div>
            @if ($focus)
                <a class="section__link" href="{{ route('public.candidates', $focus->public_id) }}">Halaman calon →</a>
            @endif
        </div>

        @if ($focus && $ballots->isNotEmpty())
            <div class="reveal">
                @include('public.partials.candidate-grid', ['ballots' => $ballots])
            </div>
        @else
            <div class="empty reveal">
                <p>Daftar calon tampil di sini setelah pemilihan disiapkan panitia.</p>
            </div>
        @endif
    </section>

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
