{{--
    Galeri terpilih semua RT sekaligus (seperti kotak "시·도지사 당선" di contoh): label RT, foto bulat, nama, jabatan.
    Tanpa angka suara (K21); tiap kotak membuka hasil RT tersebut.
    $unitCards: kartu hasil yang terikat RT, $election: pemilihan yang ditampilkan.
--}}
@php($decidedCount = $unitCards->where('decided', true)->count())
<div class="subsection reveal">
    <div class="subsection__head">
        <h3 class="subsection__title">Terpilih di setiap RT</h3>
        <p class="tally">
            <span class="tally__done">Ditetapkan: {{ $decidedCount }}</span>
            @if ($unitCards->count() > $decidedCount)
                <span class="tally__pending">Belum: {{ $unitCards->count() - $decidedCount }}</span>
            @endif
        </p>
    </div>

    <div class="winners">
        @foreach ($unitCards as $card)
            <a class="winner {{ $card['decided'] ? '' : 'winner--pending' }}" href="{{ route('public.show', [$election->public_id, 'rt' => $card['unit']->code]) }}">
                <span class="winner__unit">{{ $card['unit']->name }}</span>
                @if ($card['decided'])
                    <span class="winner__faces">
                        @foreach ($card['candidates'] as $candidate)
                            @include('public.partials.portrait', ['candidate' => $candidate, 'class' => 'portrait--winner'])
                        @endforeach
                    </span>
                    <span class="winner__name">{{ $card['candidates']->pluck('name')->implode(', ') }}</span>
                    <span class="winner__office">{{ $card['office'] }} · {{ $card['label'] }}</span>
                @else
                    <span class="winner__empty">Belum ditetapkan</span>
                    <span class="winner__office">{{ $card['office'] }}</span>
                @endif
            </a>
        @endforeach
    </div>
</div>
