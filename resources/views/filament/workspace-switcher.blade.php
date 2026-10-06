@php($mode = \App\Filament\Support\Workspace::current())

@if ($mode)
    <div class="flex items-center gap-2">
        <x-filament::badge :color="$mode === \App\Enums\ElectionMode::Dadakan ? 'warning' : 'info'" size="lg">
            Mode {{ $mode === \App\Enums\ElectionMode::Dadakan ? 'Dadakan' : 'Resmi' }}
        </x-filament::badge>
        @if (\App\Filament\Support\Workspace::canSwitch())
            <x-filament::button tag="a" :href="route('workspace.switch', 'pilih')" size="sm" color="gray" icon="heroicon-m-arrows-right-left">
                Ganti mode
            </x-filament::button>
        @endif
    </div>
@endif
