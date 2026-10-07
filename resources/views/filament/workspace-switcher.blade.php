@php($mode = \App\Filament\Support\Workspace::current())

@if ($mode)
    @php($focused = \App\Filament\Support\Workspace::election())
    <div class="flex items-center gap-2">
        <x-filament::badge :color="$mode === \App\Enums\ElectionMode::Dadakan ? 'warning' : 'info'" size="lg">
            Mode {{ $mode === \App\Enums\ElectionMode::Dadakan ? 'Dadakan' : 'Resmi' }}
        </x-filament::badge>
        @if ($focused)
            <span class="hidden items-center gap-2 rounded-lg bg-gray-50 px-3 py-1 text-sm ring-1 ring-gray-950/5 md:inline-flex dark:bg-white/5 dark:ring-white/10" title="Pemilihan yang sedang dikerjakan">
                <span class="font-semibold">{{ $focused->name }}</span>
                <x-filament::badge size="sm" :color="$focused->status->getColor()">{{ $focused->status->isLive() ? '● ' : '' }}{{ $focused->status->getLabel() }}</x-filament::badge>
            </span>
        @endif
        @if (\App\Filament\Support\Workspace::canChooseElection())
            <x-filament::button tag="a" :href="route('workspace.election')" size="sm" color="gray" icon="heroicon-m-queue-list">
                {{ $focused ? 'Ganti pemilihan' : 'Pilih pemilihan' }}
            </x-filament::button>
        @endif
        @if (\App\Filament\Support\Workspace::canSwitch())
            <x-filament::button tag="a" :href="route('workspace.switch', 'pilih')" size="sm" color="gray" icon="heroicon-m-arrows-right-left">
                Ganti mode
            </x-filament::button>
        @endif
    </div>
@endif
