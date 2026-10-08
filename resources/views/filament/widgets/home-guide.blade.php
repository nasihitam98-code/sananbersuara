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
                            @php($startCard = ($this->startElectionAction)(['election' => $election->public_id]))
                            @if ($startCard->isVisible())
                                <div>{{ $startCard }}</div>
                            @endif
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

                {{-- Dashboard pemilihan: kepala, angka, tahapan + langkah sekarang, akses cepat. --}}
                <div class="space-y-6">
                    <div class="relative overflow-hidden rounded-2xl bg-linear-to-br from-primary-600 to-primary-800 p-6 text-white shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="space-y-2">
                                <p class="text-sm font-medium text-white/70">Mode {{ $primary->mode->getLabel() }}</p>
                                <h2 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $primary->name }}</h2>
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-sm font-semibold ring-1 ring-white/25">
                                    @if ($primary->status->isLive())
                                        <span class="h-2 w-2 animate-pulse rounded-full bg-emerald-300"></span>
                                    @endif
                                    {{ $primary->status->getLabel() }}
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 [&_.fi-link]:text-white/80 [&_.fi-link:hover]:text-white">
                                @php($startHere = ($this->startElectionAction)(['election' => $primary->public_id]))
                                @if ($startHere->isVisible()) {{ $startHere }} @endif
                                @php($deleteHere = ($this->deleteElectionAction)(['election' => $primary->public_id]))
                                @if ($deleteHere->isVisible()) {{ $deleteHere }} @endif
                                @php($cancelHere = ($this->cancelElectionAction)(['election' => $primary->public_id]))
                                @if ($cancelHere->isVisible()) {{ $cancelHere }} @endif
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        @foreach ($this->dashboardStats($primary) as $stat)
                            <div class="flex items-center gap-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                <span @class([
                                    'grid h-12 w-12 shrink-0 place-items-center rounded-xl',
                                    'bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400' => $stat['color'] === 'primary',
                                    'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400' => $stat['color'] === 'info',
                                    'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' => $stat['color'] === 'success',
                                    'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' => $stat['color'] === 'warning',
                                ])>
                                    <x-filament::icon :icon="$stat['icon']" class="h-6 w-6" />
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm text-gray-500 dark:text-gray-400">{{ $stat['label'] }}</p>
                                    <p class="text-2xl font-bold tracking-tight tabular-nums">{{ $stat['value'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="grid items-start gap-6 xl:grid-cols-3">
                        <x-filament::section class="xl:col-span-2" heading="Tahapan">
                            <ol class="grid grid-cols-3 gap-2 sm:grid-cols-6">
                                @foreach ($this->stages($primary) as $index => $item)
                                    @php($classes = \Illuminate\Support\Arr::toCssClasses([
                                        'flex h-full flex-col items-center gap-1 rounded-xl px-2 py-3 text-center text-xs font-semibold sm:text-sm',
                                        'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300' => $index < $stage,
                                        'bg-primary-600 text-white shadow-sm' => $index === $stage,
                                        'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' => $index > $stage,
                                        'hover:ring-2 hover:ring-primary-500' => $item['url'] !== null,
                                    ]))
                                    <li>
                                        @if ($item['url'])
                                            <a href="{{ $item['url'] }}" class="{{ $classes }}" title="{{ $item['description'] }} (klik untuk membuka)">
                                                <span class="text-base">{{ $index < $stage ? '✓' : $index + 1 }}</span>{{ $item['label'] }}
                                            </a>
                                        @else
                                            <span class="{{ $classes }}" title="{{ $item['description'] }}">
                                                <span class="text-base">{{ $index < $stage ? '✓' : $index + 1 }}</span>{{ $item['label'] }}
                                            </span>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        </x-filament::section>

                        <div class="flex flex-col justify-between gap-3 rounded-2xl bg-primary-50 p-5 ring-1 ring-primary-100 dark:bg-primary-500/10 dark:ring-primary-500/20">
                            <div>
                                <p class="text-xs font-semibold tracking-wider text-primary-700 uppercase dark:text-primary-300">Langkah sekarang</p>
                                <p class="mt-2 text-gray-800 dark:text-gray-100">{{ $next['hint'] }}</p>
                            </div>
                            @if ($next['buttons'] !== [])
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($next['buttons'] as $button)
                                        <x-filament::button
                                            tag="a"
                                            :href="$button['url']"
                                            :target="$button['newTab'] ? '_blank' : null"
                                            :color="$loop->first ? 'primary' : 'gray'"
                                            :icon="$loop->first ? 'heroicon-m-arrow-right' : null"
                                            icon-position="after"
                                        >
                                            {{ $button['label'] }}
                                        </x-filament::button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>

                    @if ($links = $this->quickLinks($primary))
                        <div>
                            <p class="mb-3 text-sm font-semibold tracking-wider text-gray-500 uppercase dark:text-gray-400">Akses cepat</p>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                @foreach ($links as $link)
                                    <a href="{{ $link['url'] }}" @if ($link['newTab']) target="_blank" @endif
                                        class="group flex items-start gap-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 transition hover:-translate-y-0.5 hover:shadow-md hover:ring-primary-500 dark:bg-gray-900 dark:ring-white/10">
                                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gray-100 text-gray-600 transition group-hover:bg-primary-600 group-hover:text-white dark:bg-white/5 dark:text-gray-300">
                                            <x-filament::icon :icon="$link['icon']" class="h-6 w-6" />
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block font-semibold">{{ $link['label'] }}@if ($link['newTab']) <span class="text-xs font-normal text-gray-400">↗</span>@endif</span>
                                            <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $link['description'] }}</span>
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

            @endif

            {{-- Arsip: pemilihan dibatalkan tetap bisa dilihat riwayat dan daftar hadirnya. --}}
            @php($archived = $this->archivedElections())
            @if ($archived->isNotEmpty())
                <x-filament::section :heading="'Arsip: pemilihan dibatalkan ('.$archived->count().')'" collapsible collapsed>
                    @foreach ($archived as $old)
                        <div @class(['flex flex-wrap items-center justify-between gap-3 py-3', 'border-t border-gray-100 dark:border-white/10' => ! $loop->first])>
                            <div>
                                <p class="font-semibold">{{ $old->name }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $old->status->getLabel() }} · {{ $this->cardSummary($old) }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if (\App\Filament\Resources\Elections\ElectionResource::canView($old))
                                    <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-m-clock" :href="\App\Filament\Resources\Elections\ElectionResource::getUrl('edit', ['record' => $old])">
                                        Riwayat
                                    </x-filament::button>
                                @endif
                                @if ($old->isDadakan() && \App\Filament\Pages\ControlRoom::canAccess())
                                    <x-filament::button tag="a" size="sm" color="gray" icon="heroicon-m-users" :href="\App\Filament\Pages\ControlRoom::getUrl(['pemilihan' => $old->public_id])">
                                        Daftar hadir
                                    </x-filament::button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </x-filament::section>
            @endif

            @if (($primary !== null || $elections->isEmpty()) && ($steps = $this->guideSteps()))
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
