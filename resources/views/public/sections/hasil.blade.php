{{-- ============ HASIL ============ --}}
<section id="hasil" class="anchor section" aria-labelledby="hasil-judul">
    {{-- Nama pemilihan sudah ada di banner; di sini cukup judul pendek (kecuali banner sedang menampilkan pemilihan lain). --}}
    <div class="section__head reveal">
        <div>
            <p class="section__kicker">Hasil resmi</p>
            <h2 class="section__title" id="hasil-judul">{{ $featured && $running->isNotEmpty() ? $featured->name : 'Hasil' }}</h2>
        </div>
        @if ($featured)
            <a class="section__link" href="{{ route('public.show', $featured->public_id) }}">Hasil lengkap per RT →</a>
        @endif
    </div>

    @if ($featured)
        @include('public.partials.result-grid', ['cards' => $cards, 'unitCards' => $unitCards])

        {{--
            Partisipasi warga: grafik batang per RT (seperti grafik 투표율 di contoh). Angka keseluruhan sudah ada
            di banner; ditulis di sini hanya bila banner sedang menampilkan pemilihan lain yang berjalan.
        --}}
        @php($hasUnitChart = $turnout && count($turnout['units']) > 1)
        @php($showOverall = $turnout && $running->isNotEmpty())
        @if ($turnout && $turnout['total'] > 0 && ($hasUnitChart || $showOverall))
            <div class="subsection reveal">
                <h3 class="subsection__title">Partisipasi warga</h3>
                <div class="card-box">
                    @if ($showOverall)
                        <p class="turnout__overall">
                            <span class="turnout__overall-value">{{ number_format($turnout['percent'], 1, ',', '') }}%</span>
                            <span><strong>{{ $turnout['voted'] }}</strong> dari <strong>{{ $turnout['total'] }}</strong> {{ $featured->isDadakan() ? 'warga hadir' : 'pemilih terdaftar' }} memilih</span>
                        </p>
                    @endif
                    @if ($hasUnitChart)
                        <p class="turnout__note">Persentase warga yang memilih di setiap RT. Batang merah = partisipasi tertinggi.</p>
                        @include('public.partials.bar-chart', [
                            'label' => 'Partisipasi per RT',
                            'bars' => collect($turnout['units'])->map(fn (array $unit): array => ['label' => $unit['unit'], 'value' => $unit['percent'], 'detail' => $unit['voted'].'/'.$unit['total']])->all(),
                        ])
                    @endif
                </div>
            </div>
        @endif

        @if ($unitCards->count() > 1)
            @include('public.partials.unit-recap', ['unitCards' => $unitCards, 'unitTurnout' => $unitTurnout, 'turnout' => $turnout, 'election' => $featured])
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
