{{--
    Tabel rekap per RT (seperti tabel rekap di bagian bawah contoh): RT, jabatan, yang ditetapkan, perolehannya,
    partisipasi, status.
    $unitCards: kartu hasil yang terikat RT, $unitTurnout: partisipasi per nama RT, $turnout: partisipasi keseluruhan.
--}}
@php($percent = fn (float $value): string => rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',').'%')
<div class="subsection reveal">
    <h3 class="subsection__title">Rekap per RT</h3>
    <div class="recap">
        <table class="recap__table">
            <thead>
                <tr>
                    <th scope="col">RT</th>
                    <th scope="col">Jabatan</th>
                    <th scope="col">Yang ditetapkan</th>
                    <th scope="col" class="recap__num">Perolehan</th>
                    <th scope="col" class="recap__num">Partisipasi</th>
                    <th scope="col" class="recap__center">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($unitCards as $card)
                    @php($unitTurn = $unitTurnout->get($card['unit']->name))
                    <tr>
                        <th scope="row" class="recap__unit">
                            <span class="recap__dot {{ $card['decided'] ? '' : 'recap__dot--pending' }}" aria-hidden="true"></span>{{ $card['unit']->name }}
                        </th>
                        <td>{{ $card['office'] }}</td>
                        <td class="recap__who">{{ $card['decided'] ? $card['candidates']->pluck('name')->implode(', ') : '–' }}</td>
                        <td class="recap__num">
                            @forelse (collect($card['tally']['rows'])->where('elected', true) as $row)
                                <span class="recap__line"><strong>{{ $percent($row['percent']) }}</strong><span class="recap__detail">{{ number_format($row['votes'], 0, ',', '.') }} suara</span></span>
                            @empty
                                –
                            @endforelse
                        </td>
                        <td class="recap__num">
                            @if ($unitTurn)
                                <strong>{{ $percent($unitTurn['percent']) }}</strong>
                                <span class="recap__detail">{{ $unitTurn['voted'] }}/{{ $unitTurn['total'] }}</span>
                            @else
                                –
                            @endif
                        </td>
                        <td class="recap__center">
                            <span class="chip {{ $card['decided'] ? 'chip--done' : 'chip--muted' }}">{{ $card['decided'] ? $card['label'] : 'Belum ditetapkan' }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
            @if ($turnout && $turnout['total'] > 0)
                <tfoot>
                    <tr>
                        <th scope="row" colspan="4">Semua RT</th>
                        <td class="recap__num">
                            <strong>{{ $percent($turnout['percent']) }}</strong>
                            <span class="recap__detail">{{ $turnout['voted'] }}/{{ $turnout['total'] }}</span>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <p class="recap__note">Partisipasi = warga yang memilih dibanding {{ $election->isDadakan() ? 'warga yang hadir' : 'pemilih terdaftar' }}. Perolehan = suara sah untuk yang ditetapkan di RT tersebut, sesuai berita acara.</p>
</div>
