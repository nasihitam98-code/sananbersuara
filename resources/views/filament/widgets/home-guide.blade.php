<x-filament-widgets::widget>
    <div class="space-y-6">
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-lg font-semibold">Halo, {{ $this->user()->name }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Mulai dari kartu pemilihan di bawah: setiap kartu menunjukkan langkah berikutnya.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-filament::button tag="a" :href="route('public.index')" target="_blank" color="gray" icon="heroicon-o-globe-alt">
                        Halaman Publik
                    </x-filament::button>
                    <x-filament::button tag="a" :href="filament()->getProfileUrl()" color="gray" icon="heroicon-o-user-circle">
                        Profil &amp; password
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section heading="Pemilihan">
            @forelse ($this->elections() as $election)
                @php($next = $this->nextStep($election))
                <div @class(['flex flex-wrap items-center justify-between gap-4 py-4', 'border-t border-gray-100 dark:border-white/10' => ! $loop->first])>
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold">{{ $election->name }}</span>
                            <x-filament::badge color="gray">{{ $election->mode->getLabel() }}</x-filament::badge>
                            <x-filament::badge :color="$election->status->getColor()">{{ $election->status->getLabel() }}</x-filament::badge>
                        </div>
                        @if ($next['hint'] !== '')
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $next['hint'] }}</p>
                        @endif
                    </div>
                    @if ($next['url'])
                        <x-filament::button tag="a" :href="$next['url']" :target="$next['newTab'] ? '_blank' : null" icon="heroicon-m-arrow-right" icon-position="after">
                            {{ $next['label'] }}
                        </x-filament::button>
                    @endif
                </div>
            @empty
                <div class="space-y-3">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada pemilihan untuk akun ini.</p>
                    @if (\App\Filament\Resources\Elections\ElectionResource::canAccess())
                        <x-filament::button tag="a" :href="\App\Filament\Resources\Elections\ElectionResource::getUrl('create')" icon="heroicon-m-plus">
                            Buat pemilihan pertama
                        </x-filament::button>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">Minta Super Admin menugaskan akun Anda ke pemilihan.</p>
                    @endif
                </div>
            @endforelse
        </x-filament::section>

        @if ($guides = $this->guides())
            <div @class(['grid gap-6', 'lg:grid-cols-2' => count($guides) > 1])>
                @foreach ($guides as $guide)
                    <x-filament::section :heading="'Panduan ' . $guide['title']" :description="$guide['description']" collapsible>
                        <ol class="space-y-3">
                            @foreach ($guide['steps'] as $step)
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
                @endforeach
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
