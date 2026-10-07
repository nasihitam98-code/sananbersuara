<x-filament-widgets::widget>
    @php($mode = $this->mode())

    <div class="space-y-6">
        @if ($mode === null)
            {{-- Langkah pertama: pilih mode --}}
            <x-filament::section>
                <p class="text-lg font-semibold">Halo, {{ $this->user()->name }}. Mau mengurus pemilihan yang mana?</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">Menu di kiri akan menyesuaikan mode yang dipilih. Bisa diganti kapan saja lewat tombol "Ganti mode" di atas.</p>
            </x-filament::section>

            <div class="grid gap-6 md:grid-cols-2">
                @foreach ($this->modeChoices() as $choice)
                    <a href="{{ $choice['url'] }}" class="block rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 transition hover:ring-2 hover:ring-primary-500 dark:bg-gray-900 dark:ring-white/10">
                        <p class="text-xl font-bold text-primary-700 dark:text-primary-300">{{ $choice['title'] }}</p>
                        <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">{{ $choice['description'] }}</p>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $choice['examples'] }}</p>
                        <p class="mt-4 font-semibold text-primary-600 dark:text-primary-400">Masuk →</p>
                    </a>
                @endforeach
            </div>
        @else
            @php($primary = $this->primaryElection())
            @php($elections = $this->elections())

            @if ($elections->isEmpty())
                <x-filament::section>
                    <p class="font-semibold">Belum ada pemilihan Mode {{ $mode->getLabel() }}.</p>
                    @if (\App\Filament\Resources\Elections\ElectionResource::canAccess())
                        <div class="mt-3">
                            <x-filament::button tag="a" :href="\App\Filament\Resources\Elections\ElectionResource::getUrl('create')" icon="heroicon-m-plus">
                                Buat pemilihan
                            </x-filament::button>
                        </div>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">Minta Super Admin menugaskan akun Anda ke pemilihan.</p>
                    @endif
                </x-filament::section>
            @elseif ($primary === null)
                {{-- Pilih pemilihan yang mau dikerjakan; semua menu lalu mengikuti pemilihan itu. --}}
                @php($live = $this->otherLiveElection())
                <x-filament::section>
                    <p class="text-lg font-semibold">Pemilihan mana yang mau dikerjakan?</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Setelah masuk, menu Calon, Meja Pintu, Ruang Kendali, dan Hasil hanya berisi pemilihan itu. Bisa diganti lewat tombol "Ganti pemilihan" di atas.</p>
                    @if ($live)
                        <p class="mt-2 text-sm font-semibold text-success-700 dark:text-success-400">● Sedang berlangsung: {{ $live->name }}</p>
                    @endif
                </x-filament::section>

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($elections as $election)
                        @php($isLive = $election->status->isLive())
                        @php($confirm = $live && ! $isLive ? "Pemilihan \"{$live->name}\" sedang berlangsung. Tetap masuk ke \"{$election->name}\"? (Voting di \"{$live->name}\" tetap berjalan.)" : null)
                        <div @class([
                            'flex flex-col justify-between gap-4 rounded-xl bg-white p-5 shadow-sm dark:bg-gray-900',
                            'ring-2 ring-success-500' => $isLive,
                            'ring-1 ring-gray-950/5 dark:ring-white/10' => ! $isLive,
                        ])>
                            <div class="space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-lg font-bold">{{ $election->name }}</p>
                                    <x-filament::badge :color="$election->status->getColor()">{{ $isLive ? '● ' : '' }}{{ $election->status->getLabel() }}</x-filament::badge>
                                </div>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $this->cardSummary($election) }}</p>
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <span @if ($confirm) x-data x-on:click="if (! confirm({{ \Illuminate\Support\Js::from($confirm) }})) { $event.preventDefault() }" @endif>
                                    <x-filament::button tag="a" :href="route('workspace.election', $election->public_id)" icon="heroicon-m-arrow-right" icon-position="after">
                                        Masuk
                                    </x-filament::button>
                                </span>
                                <div class="flex items-center gap-3">
                                    @if (($this->deleteElectionAction)(['election' => $election->public_id])->isVisible()) {{ ($this->deleteElectionAction)(['election' => $election->public_id]) }} @endif
                                    @if (($this->cancelElectionAction)(['election' => $election->public_id])->isVisible()) {{ ($this->cancelElectionAction)(['election' => $election->public_id]) }} @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if (\App\Filament\Resources\Elections\ElectionResource::canCreate())
                    <div>
                        <x-filament::button tag="a" color="gray" :href="\App\Filament\Resources\Elections\ElectionResource::getUrl('create')" icon="heroicon-m-plus">
                            Buat pemilihan baru
                        </x-filament::button>
                    </div>
                @endif
            @else
                @php($stage = $this->stageIndex($primary))
                @php($next = $this->nextStep($primary))
                @php($otherLive = $this->otherLiveElection($primary))

                @if ($otherLive)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-warning-50 p-4 text-warning-800 dark:bg-warning-500/10 dark:text-warning-300" role="alert">
                        <p>⚠ Pemilihan lain sedang berlangsung: <strong>{{ $otherLive->name }}</strong>. Anda sedang mengurus <strong>{{ $primary->name }}</strong>.</p>
                        <x-filament::button tag="a" size="sm" color="warning" :href="route('workspace.election', $otherLive->public_id)">Masuk ke {{ $otherLive->name }}</x-filament::button>
                    </div>
                @endif

                {{-- Pemilihan utama: tahap sekarang + tombol yang perlu ditekan --}}
                <x-filament::section>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-lg font-bold">{{ $primary->name }}</span>
                        <x-filament::badge :color="$primary->status->getColor()">{{ $primary->status->getLabel() }}</x-filament::badge>
                    </div>

                    <ol class="mt-5 grid grid-cols-3 gap-2 sm:grid-cols-6">
                        @foreach ($this->stages($primary) as $index => $item)
                            @php($classes = \Illuminate\Support\Arr::toCssClasses([
                                'block rounded-lg px-2 py-2 text-center text-xs font-semibold sm:text-sm',
                                'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300' => $index < $stage,
                                'bg-primary-600 text-white' => $index === $stage,
                                'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' => $index > $stage,
                                'underline-offset-2 hover:underline hover:ring-2 hover:ring-primary-500' => $item['url'] !== null,
                            ]))
                            <li>
                                @if ($item['url'])
                                    <a href="{{ $item['url'] }}" class="{{ $classes }}" title="{{ $item['description'] }} (klik untuk membuka)">
                                        {{ $index < $stage ? '✓ ' : ($index + 1).'. ' }}{{ $item['label'] }}
                                    </a>
                                @else
                                    <span class="{{ $classes }}" title="{{ $item['description'] }}">
                                        {{ $index < $stage ? '✓ ' : ($index + 1).'. ' }}{{ $item['label'] }}
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Klik tahap yang sudah lewat (✓) atau tahap sekarang untuk membuka halamannya.</p>

                    <div class="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                        @foreach ($this->preparationSummary($primary) as $item)
                            <span><span class="text-gray-500 dark:text-gray-400">{{ $item['label'] }}:</span> <strong>{{ $item['value'] }}</strong></span>
                        @endforeach
                    </div>

                    <div class="mt-5 rounded-xl bg-primary-50 p-4 dark:bg-primary-500/10">
                        <p class="text-sm font-semibold uppercase tracking-wide text-primary-700 dark:text-primary-300">Sekarang</p>
                        <p class="mt-1">{{ $next['hint'] }}</p>
                        @if ($next['buttons'] !== [])
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($next['buttons'] as $button)
                                    <x-filament::button
                                        tag="a"
                                        :href="$button['url']"
                                        :target="$button['newTab'] ? '_blank' : null"
                                        :color="$loop->first ? 'primary' : 'gray'"
                                        :size="$loop->first ? 'lg' : 'md'"
                                        :icon="$loop->first ? 'heroicon-m-arrow-right' : null"
                                        icon-position="after"
                                    >
                                        {{ $button['label'] }}
                                    </x-filament::button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </x-filament::section>

            @endif

            @if ($steps = $this->guideSteps())
                <x-filament::section heading="Panduan langkah Mode {{ $mode->getLabel() }}" collapsible :collapsed="$primary !== null">
                    <ol class="space-y-3">
                        @foreach ($steps as $step)
                            <li class="flex gap-3">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-bold text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">{{ $loop->iteration }}</span>
                                <div class="min-w-0">
                                    <x-filament::link :href="$step['url']" weight="semibold">{{ $step['label'] }}</x-filament::link>
                                    @if ($step['detail'] !== '')
                                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $step['detail'] }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </x-filament::section>
            @endif
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
