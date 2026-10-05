@if ($elections->count() > 1)
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm text-gray-500">Pemilihan:</span>
        @foreach ($elections as $item)
            <x-filament::button
                size="sm"
                :color="$election?->is($item) ? 'primary' : 'gray'"
                wire:click="selectElection('{{ $item->public_id }}')"
            >
                {{ $item->name }} · {{ $item->status->getLabel() }}
            </x-filament::button>
        @endforeach
    </div>
@elseif ($election)
    <div class="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
        <span class="font-semibold">{{ $election->name }}</span>
        <x-filament::badge :color="$election->status->getColor()">{{ $election->status->getLabel() }}</x-filament::badge>
    </div>
@endif
