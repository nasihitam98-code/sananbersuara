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
                <div class="flex flex-wrap justify-center gap-3">
                    <x-filament::button size="xl" wire:click="reveal" icon="heroicon-o-presentation-chart-bar">Tampilkan Hasil di sini</x-filament::button>
                    <x-filament::button size="xl" color="gray" tag="a" target="_blank" icon="heroicon-o-arrow-top-right-on-square" :href="route('screens.results', $election->public_id)">Buka di tab baru (proyektor)</x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @else
        @php($p = $this->participation())

        <div class="flex flex-wrap gap-2" x-data>
            <x-filament::button tag="a" target="_blank" icon="heroicon-o-presentation-chart-bar" :href="route('screens.results', $election->public_id)">
                Buka di tab baru (proyektor)
            </x-filament::button>
            <x-filament::button color="gray" icon="heroicon-o-arrows-pointing-out" x-on:click="document.getElementById('hasil-presentasi').requestFullscreen()">
                Layar penuh di sini
            </x-filament::button>
        </div>

        {{-- Area presentasi: saat layar penuh hanya bagian ini yang tampil (menu dan bilah atas tersembunyi). Keluar: Esc. --}}
        <div id="hasil-presentasi" class="space-y-6 [&:fullscreen]:overflow-y-auto [&:fullscreen]:bg-gray-50 [&:fullscreen]:p-10 dark:[&:fullscreen]:bg-gray-950">
        <h1 class="hidden text-center text-4xl font-black [:fullscreen_&]:block">{{ $election->name }}</h1>

        @if ($p)
        <div class="grid gap-4 grid-cols-3">
            @foreach ([['Hadir terdata', $p['attendees']], ['Memilih', $p['voted']], ['Partisipasi', $p['percent'].'%']] as [$label, $value])
                <x-filament::section>
                    <p class="text-sm text-gray-500">{{ $label }}</p>
                    <p class="text-3xl font-black tabular-nums">{{ $value }}</p>
                </x-filament::section>
            @endforeach
        </div>
        @endif


        @foreach ($this->results() as $block)
            @php($tally = $block['tally'])
            @php($rows = $tally['candidates'])
            @php($top = $rows[0]['votes'] ?? 0)

            {{-- Pengumuman: peringkat dibuka dari bawah ke atas tiap tombol "Berikutnya"; teratas terakhir dengan podium. --}}
            <div
                x-data="{
                    total: {{ count($rows) }},
                    shown: 0,
                    isShown(index) { return this.shown > this.total - 1 - index; },
                    next() {
                        if (this.shown < this.total) {
                            this.shown++;
                            if (this.shown === this.total) { this.celebrate(); }
                        }
                    },
                    showAll() { this.shown = this.total; },
                    restart() { this.shown = 0; },
                    celebrate() {
                        if ({{ $top }} === 0) { return; }
                        const colors = ['#4b44c4', '#0f8f86', '#d6303a', '#f59e0b', '#2453d6', '#16a34a'];
                        for (let i = 0; i < 160; i++) {
                            const piece = document.createElement('div');
                            piece.setAttribute('aria-hidden', 'true');
                            piece.style.position = 'fixed';
                            piece.style.top = '-20px';
                            piece.style.left = (Math.random() * 100) + 'vw';
                            piece.style.width = (6 + Math.random() * 8) + 'px';
                            piece.style.height = (10 + Math.random() * 10) + 'px';
                            piece.style.background = colors[i % colors.length];
                            piece.style.zIndex = '9999';
                            piece.style.pointerEvents = 'none';
                            piece.style.borderRadius = '2px';
                            document.body.appendChild(piece);
                            const drift = ((Math.random() - 0.5) * 200) + 'px';
                            const spin = (360 + Math.random() * 720) + 'deg';
                            piece.animate(
                                [{ transform: 'translateY(0) rotate(0deg)' }, { transform: 'translateY(110vh) translateX(' + drift + ') rotate(' + spin + ')' }],
                                { duration: 2500 + Math.random() * 2500, delay: Math.random() * 600, easing: 'cubic-bezier(.2,.6,.4,1)' },
                            ).onfinish = () => piece.remove();
                        }
                    },
                }"
            >
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

                <div class="mb-6 flex flex-wrap gap-2">
                    <x-filament::button size="lg" icon="heroicon-o-forward" x-show="shown < total" x-on:click="next()">
                        <span x-text="shown === 0 ? 'Mulai pengumuman' : (shown === total - 1 ? 'Tampilkan peringkat teratas' : 'Berikutnya')">Mulai pengumuman</span>
                    </x-filament::button>
                    <x-filament::button color="gray" x-show="shown < total" x-on:click="showAll()">Tampilkan semua</x-filament::button>
                    <x-filament::button color="gray" icon="heroicon-o-arrow-path" x-show="shown > 0" x-on:click="restart()">Ulang</x-filament::button>
                </div>

                {{-- Podium tiga teratas, muncul setelah semua peringkat dibuka. --}}
                @if ($top > 0)
                    <div x-show="shown === total" x-transition.duration.700ms class="mb-8 grid grid-cols-3 items-end gap-4 text-center">
                        {{-- Per peringkat (bukan per baris): calon seri berbagi satu tempat, tidak ada yang "dipilih" sistem. --}}
                        @php($podiumGroups = collect($rows)->filter(fn (array $row): bool => $row['votes'] > 0 && $row['rank'] <= 3)->groupBy('rank')->values()->take(3))
                        @foreach ([1 => 'h-28', 0 => 'h-40', 2 => 'h-20'] as $podiumIndex => $height)
                            @php($group = $podiumGroups->get($podiumIndex))
                            @if ($group !== null)
                                @php($podium = $group->first())
                                <div class="flex flex-col items-center gap-2">
                                    <span @class([
                                        'grid h-11 w-11 place-items-center rounded-full text-lg font-black text-white shadow ring-4 ring-white dark:ring-gray-900',
                                        'bg-amber-500' => $podiumIndex === 0,
                                        'bg-slate-400' => $podiumIndex === 1,
                                        'bg-orange-700' => $podiumIndex === 2,
                                    ])>{{ $podium['rank'] }}</span>
                                    @if ($group->count() > 1)
                                        <span class="{{ $podiumIndex === 0 ? 'h-32 w-32' : 'h-24 w-24' }} grid place-items-center rounded-full bg-gray-200 text-4xl font-black text-primary-700">{{ $group->count() }}</span>
                                        <p class="{{ $podiumIndex === 0 ? 'text-2xl' : 'text-lg' }} font-bold">{{ $group->count() }} calon seri</p>
                                        <p class="max-w-xs text-sm text-gray-500">{{ $group->take(3)->map(fn (array $row): string => $row['candidate']->name)->implode(', ') }}{{ $group->count() > 3 ? ', dan '.($group->count() - 3).' lainnya' : '' }}</p>
                                    @else
                                        @if ($podium['candidate']->photoUrl('card'))
                                            <img src="{{ $podium['candidate']->photoUrl('card') }}" alt="Foto {{ $podium['candidate']->name }}" class="{{ $podiumIndex === 0 ? 'h-32 w-32' : 'h-24 w-24' }} rounded-full object-cover ring-4 {{ $podiumIndex === 0 ? 'ring-warning-400' : 'ring-gray-300' }}">
                                        @else
                                            <span class="{{ $podiumIndex === 0 ? 'h-32 w-32 text-4xl' : 'h-24 w-24 text-2xl' }} grid place-items-center rounded-full bg-gray-200 font-black text-primary-700">{{ $podium['candidate']->initials() }}</span>
                                        @endif
                                        <p class="{{ $podiumIndex === 0 ? 'text-2xl' : 'text-lg' }} font-bold">{{ $podium['candidate']->name }}</p>
                                    @endif
                                    <p class="text-gray-500">{{ $podium['votes'] }} suara{{ $group->count() > 1 ? ' masing-masing' : '' }} · {{ $podium['percent'] }}%</p>
                                    <div class="{{ $height }} grid w-full place-items-center rounded-t-xl text-3xl font-black text-white {{ $podiumIndex === 0 ? 'bg-warning-400' : 'bg-primary-400' }}">#{{ $podium['rank'] }}</div>
                                </div>
                            @else
                                <div></div>
                            @endif
                        @endforeach
                    </div>
                @endif

                <p x-show="shown === 0" class="py-10 text-center text-xl text-gray-500">Tekan <strong>Mulai pengumuman</strong>. Peringkat dibuka dari bawah; peringkat teratas paling akhir.</p>

                <ol class="space-y-3">
                    @foreach ($rows as $index => $row)
                        @php($candidate = $row['candidate'])
                        @php($isTop = $top > 0 && $row['votes'] === $top)
                        <li
                            x-show="isShown({{ $index }})"
                            x-transition:enter="transition ease-out duration-500"
                            x-transition:enter-start="opacity-0 -translate-y-4"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-data="{ width: 0, count: 0 }"
                            x-effect="
                                if (isShown({{ $index }})) {
                                    setTimeout(() => width = {{ $top > 0 ? round($row['votes'] / $top * 100, 1) : 0 }}, 80);
                                    const started = performance.now();
                                    const step = (now) => {
                                        const progress = Math.min(1, (now - started) / 1200);
                                        count = Math.round({{ $row['votes'] }} * progress);
                                        if (progress < 1) { requestAnimationFrame(step); }
                                    };
                                    requestAnimationFrame(step);
                                } else { width = 0; count = 0; }
                            "
                            class="grid grid-cols-[3rem_3.5rem_1fr_auto] items-center gap-3"
                        >
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
                                    <div class="h-4 rounded-full transition-[width] duration-1000 ease-out {{ $isTop ? 'bg-warning-500' : 'bg-primary-400' }}" x-bind:style="'width: ' + width + '%'"></div>
                                </div>
                            </div>
                            <div class="text-right tabular-nums">
                                <p class="text-2xl font-black">{{ $row['percent'] }}%</p>
                                <p class="text-sm text-gray-500"><span x-text="count">{{ $row['votes'] }}</span> suara</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>
            </div>
        @endforeach


        </div>

        <p class="text-sm text-gray-500">Sistem hanya menampilkan data. Penetapan calon yang lolos/terpilih dilakukan panitia.</p>
        <div class="flex flex-wrap gap-2">
            @if (\App\Filament\Pages\VerificationDesk::canAccess())
                <x-filament::button tag="a" icon="heroicon-m-arrow-right" icon-position="after" :href="\App\Filament\Pages\VerificationDesk::getUrl(['pemilihan' => $election->public_id])">
                    Lanjut: Sahkan &amp; Umumkan
                </x-filament::button>
            @endif
            <x-filament::button color="gray" wire:click="hide">Sembunyikan hasil</x-filament::button>
            <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" tag="a" :href="route('recap.export', $election->public_id)">Unduh rekap Excel</x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
