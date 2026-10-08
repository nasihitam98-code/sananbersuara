{{--
    Grafik batang tegak (seperti grafik partisipasi per wilayah di referensi). Tinggi batang lewat atribut SVG
    (bukan atribut style, aman untuk CSP ketat); batang tertinggi diberi warna sorot. Skala 0–100%.
    $bars: array<int, array{label: string, value: float, detail: ?string}>
--}}
@php($count = max(1, count($bars)))
@php($slot = $slot ?? 72)
@php($width = max(360, $count * $slot + 60))
@php($top = 34)
@php($plot = 180)
@php($maxValue = collect($bars)->max('value'))
<div class="chart" role="img" aria-label="{{ $label }}: {{ collect($bars)->map(fn (array $bar): string => $bar['label'].' '.$bar['value'].'%')->implode(', ') }}">
    <svg class="chart__svg" width="{{ $width }}" height="{{ $top + $plot + 52 }}" viewBox="0 0 {{ $width }} {{ $top + $plot + 52 }}" preserveAspectRatio="xMinYMin meet" aria-hidden="true">
        @foreach ([0, 50, 100] as $tick)
            @php($y = $top + $plot - $plot * $tick / 100)
            <line class="chart__grid" x1="40" x2="{{ $width - 10 }}" y1="{{ $y }}" y2="{{ $y }}"></line>
            <text class="chart__tick" x="32" y="{{ $y + 4 }}" text-anchor="end">{{ $tick }}</text>
        @endforeach

        @foreach ($bars as $index => $bar)
            @php($barHeight = max(2, $plot * min(100, $bar['value']) / 100))
            @php($x = 50 + $index * $slot + ($slot - 40) / 2)
            @php($isTop = $bar['value'] === $maxValue && $maxValue > 0)
            <g class="chart__bar {{ $isTop ? 'chart__bar--top' : '' }}">
                <rect class="chart__track" x="{{ $x }}" y="{{ $top }}" width="40" height="{{ $plot }}" rx="8"></rect>
                <rect class="chart__fill" x="{{ $x }}" y="{{ $top + $plot - $barHeight }}" width="40" height="{{ $barHeight }}" rx="8"></rect>
                <text class="chart__value" x="{{ $x + 20 }}" y="{{ $top + $plot - $barHeight - 8 }}" text-anchor="middle">{{ rtrim(rtrim(number_format($bar['value'], 1, ',', ''), '0'), ',') }}</text>
                <text class="chart__label" x="{{ $x + 20 }}" y="{{ $top + $plot + 22 }}" text-anchor="middle">{{ $bar['label'] }}</text>
                @if ($bar['detail'] ?? null)
                    <text class="chart__detail" x="{{ $x + 20 }}" y="{{ $top + $plot + 40 }}" text-anchor="middle">{{ $bar['detail'] }}</text>
                @endif
            </g>
        @endforeach
    </svg>
</div>
