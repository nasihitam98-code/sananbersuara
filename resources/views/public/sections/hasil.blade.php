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
