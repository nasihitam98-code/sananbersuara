{{-- ============ QUICK COUNT (sementara, belum resmi) ============ --}}
@php($snapshot = $quick['snapshot'])
@php($quickCards = $snapshot['cards'])
@php($quickUnitCards = $quickCards->filter(fn (array $card): bool => $card['unit'] !== null)->values())
@php($quickTurnout = $snapshot['turnout'])
<section id="quick-count" class="anchor section" aria-labelledby="quick-count-judul">
    <div class="section__head reveal">
        <div>
            <p class="section__kicker">Quick count · hasil sementara</p>
            <h2 class="section__title" id="quick-count-judul">{{ $quick['election']->name }}</h2>
        </div>
    </div>

    <p class="quick-note reveal" role="note">
        <strong>{{ $snapshot['final'] ? 'Voting selesai' : 'Belum resmi' }}</strong>
        <span>
            Data pukul {{ $snapshot['at']->format('H:i') }}.
            {{ $snapshot['final']
                ? 'Ini angka akhir hitung cepat. Hasil resmi diumumkan setelah panitia memeriksa dan menetapkannya.'
                : 'Diperbarui tiap '.\App\Services\Results\QuickCount::INTERVAL_MINUTES.' menit selama voting. Hasil resmi diumumkan setelah panitia menetapkannya.' }}
        </span>
        @if ($quickTurnout['total'] > 0)
            <span>Partisipasi <strong>{{ number_format($quickTurnout['percent'], 1, ',', '') }}%</strong> ({{ $quickTurnout['voted'] }} dari {{ $quickTurnout['total'] }} {{ $quick['election']->isDadakan() ? 'warga hadir' : 'pemilih terdaftar' }})</span>
        @endif
    </p>

    @include('public.partials.result-grid', ['cards' => $quickCards, 'unitCards' => $quickUnitCards])

    @if ($quick['election']->setting('public_profiles'))
        @include('public.partials.profile-dialogs', ['cards' => $quickCards])
    @endif

    @if (count($quickTurnout['units']) > 1)
        <div class="subsection reveal">
            <h3 class="subsection__title">Partisipasi sementara per RT</h3>
            <div class="card-box">
                @include('public.partials.bar-chart', [
                    'label' => 'Partisipasi sementara per RT',
                    'bars' => collect($quickTurnout['units'])->map(fn (array $unit): array => ['label' => $unit['unit'], 'value' => $unit['percent'], 'detail' => $unit['voted'].'/'.$unit['total']])->all(),
                ])
            </div>
        </div>
    @endif
</section>
