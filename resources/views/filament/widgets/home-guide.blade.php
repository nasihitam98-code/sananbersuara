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

            @if ($primary === null)
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
            @else
                @php($stage = $this->stageIndex($primary))
                @php($next = $this->nextStep($primary))

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

                @php($others = $this->elections()->reject(fn ($election) => $election->is($primary)))
                @if ($others->isNotEmpty())
                    <x-filament::section heading="Pemilihan lain di mode ini" collapsible collapsed>
                        @foreach ($others as $election)
                            @php($otherNext = $this->nextStep($election))
                            <div @class(['flex flex-wrap items-center justify-between gap-3 py-3', 'border-t border-gray-100 dark:border-white/10' => ! $loop->first])>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{ $election->name }}</span>
                                    <x-filament::badge :color="$election->status->getColor()">{{ $election->status->getLabel() }}</x-filament::badge>
                                </div>
                                @if ($otherNext['buttons'] !== [])
                                    <x-filament::button tag="a" :href="$otherNext['buttons'][0]['url']" :target="$otherNext['buttons'][0]['newTab'] ? '_blank' : null" color="gray" size="sm">
                                        {{ $otherNext['buttons'][0]['label'] }}
                                    </x-filament::button>
                                @endif
                            </div>
                        @endforeach
                    </x-filament::section>
                @endif
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
</x-filament-widgets::widget>
