<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Hasil · {{ $election->name }}</title>
    @vite(['resources/css/voter.css', 'resources/js/results-screen.js'])
</head>
<body class="screen results">
    <header class="topbar screen__topbar">
        <div>
            <p class="topbar__label">Hasil pemungutan suara</p>
            <p class="topbar__title">{{ $election->name }}</p>
        </div>
        <button type="button" class="screen__fullscreen" data-fullscreen>⛶ Layar penuh</button>
    </header>

    <main class="results__wrap">
        @if ($participation)
            <p class="results__participation">
                Hadir terdata <strong>{{ $participation['attendees'] }}</strong>
                · Memilih <strong>{{ $participation['voted'] }}</strong>
                · Partisipasi <strong>{{ $participation['percent'] }}%</strong>
            </p>
        @endif

        @foreach ($blocks as $block)
            @php($tally = $block['tally'])
            @php($rows = $tally['candidates'])
            @php($top = $rows[0]['votes'] ?? 0)

            <section class="card results__block" data-block data-total="{{ count($rows) }}" data-top="{{ $top }}">
                <h1 class="results__title">{{ $block['title'] }}{{ count($election->rounds) > 1 ? ' · Putaran '.$block['round'] : '' }}</h1>
                <p class="muted">
                    Suara sah {{ $tally['valid'] }} · Dibatalkan {{ $tally['cancelled'] }}
                    @if ($tally['tie_at_top'])
                        · <strong class="results__tie">SERI di peringkat teratas, panitia yang menetapkan.</strong>
                    @endif
                </p>

                <div class="results__controls">
                    <button type="button" class="btn btn--primary" data-next>Mulai pengumuman</button>
                    <button type="button" class="btn btn--ghost" data-show-all>Tampilkan semua</button>
                    <button type="button" class="btn btn--ghost hidden" data-restart>Ulang</button>
                </div>

                <p class="results__hint" data-hint>Peringkat dibuka dari bawah; peringkat teratas paling akhir. (Tombol spasi / panah kanan = berikutnya)</p>

                @if ($top > 0)
                    {{-- Podium per peringkat (bukan per baris): calon seri berbagi satu tempat, tidak ada yang "dipilih" sistem. --}}
                    @php($podiumGroups = collect($rows)->filter(fn (array $row): bool => $row['votes'] > 0 && $row['rank'] <= 3)->groupBy('rank')->values()->take(3))
                    <div class="podium hidden" data-podium>
                        @foreach ([1, 0, 2] as $podiumIndex)
                            @php($group = $podiumGroups->get($podiumIndex))
                            @if ($group === null)
                                <div></div>
                                @continue
                            @endif
                            @php($first = $group->first())
                            <div class="podium__place podium__place--{{ $podiumIndex + 1 }}">
                                <span class="podium__badge podium__badge--{{ $podiumIndex + 1 }}">{{ $first['rank'] }}</span>
                                @if ($group->count() === 1)
                                    @if ($first['candidate']->photoUrl('card'))
                                        <img class="podium__photo" src="{{ $first['candidate']->photoUrl('card') }}" alt="Foto {{ $first['candidate']->name }}">
                                    @else
                                        <span class="podium__photo podium__photo--initials">{{ $first['candidate']->initials() }}</span>
                                    @endif
                                    <p class="podium__name">{{ $first['candidate']->name }}</p>
                                @else
                                    <span class="podium__photo podium__photo--initials podium__photo--tie">{{ $group->count() }}</span>
                                    <p class="podium__name">{{ $group->count() }} calon seri</p>
                                    <p class="podium__tie-names">
                                        {{ $group->take(3)->map(fn (array $row): string => $row['candidate']->name)->implode(', ') }}{{ $group->count() > 3 ? ', dan '.($group->count() - 3).' lainnya' : '' }}
                                    </p>
                                @endif
                                <p class="muted">{{ $first['votes'] }} suara{{ $group->count() > 1 ? ' masing-masing' : '' }} · {{ $first['percent'] }}%</p>
                                <div class="podium__block">#{{ $first['rank'] }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <ol class="results__list">
                    @foreach ($rows as $index => $row)
                        @php($candidate = $row['candidate'])
                        <li class="results__row hidden {{ $top > 0 && $row['votes'] === $top ? 'results__row--top' : '' }}" data-row data-index="{{ $index }}" data-votes="{{ $row['votes'] }}" data-width="{{ $top > 0 ? round($row['votes'] / $top * 100, 1) : 0 }}">
                            <span class="results__rank">#{{ $row['rank'] }}</span>
                            @if ($candidate->photoUrl('thumb'))
                                <img class="results__photo" src="{{ $candidate->photoUrl('thumb') }}" alt="Foto {{ $candidate->name }}">
                            @else
                                <span class="results__photo results__photo--initials">{{ $candidate->initials() }}</span>
                            @endif
                            <div class="results__info">
                                <p class="results__name"><span class="muted">No. {{ $candidate->displayNumber() }}</span> · {{ $candidate->nameWithOrigin() }}</p>
                                <div class="results__bar" role="presentation"><div class="results__bar-fill" data-bar></div></div>
                            </div>
                            <div class="results__score">
                                <p class="results__percent">{{ $row['percent'] }}%</p>
                                <p class="muted"><span data-count>0</span> suara</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endforeach

        <p class="muted results__note">Sistem hanya menampilkan data. Penetapan calon yang lolos/terpilih dilakukan panitia.</p>
    </main>
</body>
</html>
