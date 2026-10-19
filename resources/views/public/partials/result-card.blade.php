{{--
    Kartu hasil satu jabatan/RT (seperti kartu hasil di contoh): calon berjajar dengan foto, persen, dan jumlah suara,
    plus batang perbandingan. Yang ditetapkan panitia disorot biru. Lebih dari 4 calon: daftar peringkat.
    Batang memakai atribut SVG (bukan atribut style) agar aman untuk CSP ketat.
--}}
@php
    // Warna: yang ditetapkan biru; calon lain bergantian merah, kuning, hijau, abu-abu (urut perolehan).
    $otherTones = ['red', 'amber', 'green', 'slate'];
    $toneIndex = 0;
    $rows = collect($card['tally']['rows'])->map(function (array $row) use ($otherTones, &$toneIndex): array {
        return $row + ['tone' => $row['elected'] ? 'blue' : $otherTones[$toneIndex++ % count($otherTones)]];
    });
    $valid = $card['tally']['valid'];
    $count = $rows->count();
    $percent = fn (float $value): string => number_format($value, 1, ',', '');
    $votes = fn (int $value): string => number_format($value, 0, ',', '.');
    // Kartu perkenalan calon (sebelum ada angka): foto, nomor, dan nama saja, urut nomor.
    $showcase = $card['showcase'] ?? false;
@endphp
<article class="race {{ $card['decided'] ? '' : 'race--pending' }} {{ $showcase ? 'race--showcase' : '' }} {{ ($wide ?? false) ? 'race--wide' : '' }}">
    {{-- Kepala kartu seperti contoh: nama wilayah tebal + lencana kecil hijau. --}}
    <header class="race__head">
        <h3 class="race__title">
            {{ $card['unit']?->name ?? $card['office'] }}
            <span class="race__office">{{ $card['unit'] !== null ? $card['office'] : 'Semua RT' }}</span>
        </h3>
        @if ($showcase)
            <span class="badge badge--muted">{{ $card['label'] }}</span>
        @elseif ($card['quick'] ?? false)
            <span class="badge badge--quick">Sementara</span>
        @elseif ($card['decided'])
            <span class="badge badge--done">{{ $card['label'] }}</span>
        @else
            <span class="badge badge--muted">Belum ditetapkan</span>
        @endif
    </header>

    @if ($count === 0)
        <p class="race__empty">Belum ada calon pada bagian ini.</p>
    @elseif ($count <= 4)
        <div class="duel duel--{{ $count }}">
            @foreach ($rows as $row)
                <div class="contender tone--{{ $row['tone'] }} {{ $row['elected'] ? 'is-elected' : '' }}">
                    @include('public.partials.portrait', ['candidate' => $row['candidate'], 'class' => 'portrait--'.$row['tone']])
                    <p class="contender__number">Nomor {{ $row['candidate']->displayNumber() }}@if ($row['candidate']->originLabel()) · {{ $row['candidate']->originLabel() }}@endif</p>
                    <p class="contender__name">{{ $row['candidate']->name }}</p>
                    @if ($showcase && ($card['profiles'] ?? false))
                        @include('public.partials.profile-peek', ['candidate' => $row['candidate']])
                    @endif
                    @unless ($showcase)
                        <p class="contender__percent">{{ $percent($row['percent']) }}<small>%</small></p>
                        <p class="contender__votes">{{ $votes($row['votes']) }} suara</p>
                    @endunless
                </div>
            @endforeach
        </div>
    @else
        <ol class="standings">
            @foreach ($rows as $row)
                <li class="standing tone--{{ $row['tone'] }} {{ $row['elected'] ? 'is-elected' : '' }}">
                    @unless ($showcase)
                        <span class="standing__rank">{{ $row['rank'] }}</span>
                    @endunless
                    @include('public.partials.portrait', ['candidate' => $row['candidate'], 'class' => 'portrait--'.$row['tone']])
                    <span class="standing__body">
                        <span class="standing__name">{{ $row['candidate']->name }}</span>
                        <span class="standing__meta">Nomor {{ $row['candidate']->displayNumber() }}@if ($row['candidate']->originLabel()) · {{ $row['candidate']->originLabel() }}@endif</span>
                        @if ($showcase && ($card['profiles'] ?? false))
                            @include('public.partials.profile-peek', ['candidate' => $row['candidate']])
                        @endif
                        @unless ($showcase)
                            <svg class="standing__bar" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true">
                                <rect class="standing__track" x="0" y="0" width="100" height="6" rx="3"></rect>
                                <rect class="share__seg share__seg--{{ $row['tone'] }}" x="0" y="0" width="{{ $row['percent'] }}" height="6" rx="3"></rect>
                            </svg>
                        @endunless
                    </span>
                    @unless ($showcase)
                        <span class="standing__figures">
                            <span class="standing__percent">{{ $percent($row['percent']) }}%</span>
                            <span class="standing__votes">{{ $votes($row['votes']) }} suara</span>
                        </span>
                    @endunless
                </li>
            @endforeach
        </ol>
    @endif

    @if ($count > 0 && ! $showcase)
        {{-- Batang perbandingan: potongan berurutan sesuai perolehan. --}}
        <span class="share" role="img" aria-label="Perbandingan suara: {{ $rows->map(fn (array $row): string => $row['candidate']->name.' '.$percent($row['percent']).'%')->implode(', ') }}">
            <svg viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true">
                @php($offset = 0.0)
                @foreach ($rows as $row)
                    <rect class="share__seg share__seg--{{ $row['tone'] }}" x="{{ $offset }}" y="0" width="{{ $row['percent'] }}" height="8"></rect>
                    @php($offset += $row['percent'])
                @endforeach
            </svg>
        </span>
        <p class="race__meaning">
            {{ $votes($valid) }} suara sah.
            @if ($card['decided'] && $card['candidates']->count() > 1)
                {{ $card['candidates']->count() }} orang ditetapkan lolos.
            @endif
        </p>
    @endif
</article>
