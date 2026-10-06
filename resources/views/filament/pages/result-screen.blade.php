<x-filament-panels::page>
    @php($election = $this->election())

    @include('filament.pages.partials.election-picker', ['election' => $election, 'elections' => $this->availableElections()])

    @if ($election === null)
        <x-filament::section>
            Hasil hanya tersedia setelah pemilihan <strong>ditutup</strong>. Selama voting berlangsung, jumlah suara per calon tidak ditampilkan kepada siapa pun.
        </x-filament::section>
    @elseif (! $revealed)
        <x-filament::section>
            <div class="py-10 text-center space-y-4">
                <p class="text-xl">Pemilihan <strong>{{ $election->name }}</strong> sudah ditutup.</p>
                <p class="text-gray-500">Tekan tombol di bawah untuk menampilkan seluruh hasil sekaligus (misalnya di proyektor). Penayangan dicatat di audit log.</p>
                <x-filament::button size="xl" wire:click="reveal" icon="heroicon-o-presentation-chart-bar">Tampilkan Hasil</x-filament::button>
            </div>
        </x-filament::section>
    @else
        @php($p = $this->participation())

        @if ($p)
        <div class="grid gap-4 grid-cols-2 md:grid-cols-4">
            @foreach ([['Hadir terdata', $p['attendees']], ['Memilih', $p['voted']], ['Partisipasi', $p['percent'].'%'], ['Dibantu', $p['assisted']]] as [$label, $value])
                <x-filament::section>
                    <p class="text-sm text-gray-500">{{ $label }}</p>
                    <p class="text-3xl font-black tabular-nums">{{ $value }}</p>
                </x-filament::section>
            @endforeach
        </div>
        @endif

        @foreach ($this->results() as $block)
            @php($tally = $block['tally'])
            @php($top = $tally['candidates'][0]['votes'] ?? 0)

            <x-filament::section>
                <x-slot name="heading">
                    {{ $block['title'] }}{{ count($election->rounds) > 1 ? ' · Putaran '.$block['round'] : '' }}
                </x-slot>
                <x-slot name="description">
                    Suara sah {{ $tally['valid'] }} · Dibatalkan {{ $tally['cancelled'] }}
                    @if ($tally['tie_at_top'])
                        · <strong class="text-warning-600">SERI di peringkat teratas, panitia yang menetapkan.</strong>
                    @endif
                </x-slot>

                <ol class="space-y-3">
                    @foreach ($tally['candidates'] as $row)
                        @php($candidate = $row['candidate'])
                        @php($isTop = $top > 0 && $row['votes'] === $top)
                        <li class="grid grid-cols-[3rem_3.5rem_1fr_auto] items-center gap-3">
                            <span class="text-2xl font-black text-gray-400 tabular-nums">#{{ $row['rank'] }}</span>
                            @if ($candidate->photoUrl('thumb'))
                                <img src="{{ $candidate->photoUrl('thumb') }}" alt="Foto {{ $candidate->name }}" class="h-14 w-14 rounded-full object-cover">
                            @else
                                <span class="grid h-14 w-14 place-items-center rounded-full bg-gray-200 font-bold text-primary-700">{{ $candidate->initials() }}</span>
                            @endif
                            <div>
                                <p class="font-semibold">
                                    <span class="text-gray-500">No. {{ $candidate->displayNumber() }}</span> · {{ $candidate->nameWithOrigin() }}
                                    @if ($candidate->status->value === 'MUNDUR')
                                        <x-filament::badge color="warning" size="sm">Mengundurkan diri</x-filament::badge>
                                    @endif
                                </p>
                                <div class="mt-1 h-4 w-full rounded-full bg-gray-100 dark:bg-white/10" role="presentation">
                                    <div class="h-4 rounded-full {{ $isTop ? 'bg-danger-500' : 'bg-primary-400' }}" style="width: {{ $row['percent'] }}%"></div>
                                </div>
                            </div>
                            <div class="text-right tabular-nums">
                                <p class="text-2xl font-black">{{ $row['percent'] }}%</p>
                                <p class="text-sm text-gray-500">{{ $row['votes'] }} suara</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>
        @endforeach

        <p class="text-sm text-gray-500">Sistem hanya menampilkan data. Penetapan calon yang lolos/terpilih dilakukan panitia.</p>
        <div class="flex flex-wrap gap-2">
            <x-filament::button color="gray" wire:click="hide">Sembunyikan hasil</x-filament::button>
            <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" tag="a" :href="route('recap.export', $election->public_id)">Unduh rekap Excel</x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
