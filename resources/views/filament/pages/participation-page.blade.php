<x-filament-panels::page>
    @php($election = $this->election())

    @if ($election === null)
        <x-filament::section>Belum ada pemilihan Mode Resmi yang berjalan.</x-filament::section>
    @else
        <div wire:poll.5s class="space-y-6">
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::badge size="lg" :color="$election->status->getColor()">{{ $election->name }} · {{ $election->status->getLabel() }}</x-filament::badge>
                <span class="text-sm text-gray-500">Hanya jumlah sudah/belum memilih. Angka per calon tidak ditampilkan selama berlangsung.</span>
            </div>

            @foreach ($this->table() as $block)
                <x-filament::section :heading="$block['title']">
                    @if ($block['total'])
                        <div class="mb-4">
                            <p class="text-4xl font-black tabular-nums">{{ $block['total']['voted'] }} <span class="text-lg font-normal text-gray-500">dari {{ $block['total']['eligible'] }} = {{ $block['total']['percent'] }}%</span></p>
                            <div class="mt-2 h-4 w-full rounded-full bg-gray-100 dark:bg-white/10"><div class="h-4 rounded-full bg-primary-500" style="width: {{ $block['total']['percent'] }}%"></div></div>
                        </div>
                    @endif

                    <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-5">
                        @foreach ($block['units'] as $row)
                            <div class="rounded-xl border border-gray-200 p-3 dark:border-white/10">
                                <p class="text-sm font-semibold">{{ $row['unit'] }}</p>
                                <p class="text-2xl font-black tabular-nums">{{ $row['voted'] }}<span class="text-sm font-normal text-gray-500"> / {{ $row['eligible'] }}</span></p>
                                <div class="mt-1 h-2 w-full rounded-full bg-gray-100 dark:bg-white/10"><div class="h-2 rounded-full bg-primary-500" style="width: {{ $row['percent'] }}%"></div></div>
                                <p class="mt-1 text-xs text-gray-500">{{ $row['percent'] }}% · belum {{ $row['not_voted'] }}@if ($row['added_during_live']) · +{{ $row['added_during_live'] }} darurat @endif</p>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        <x-filament::section heading="Belum memilih" collapsible>
            <div class="flex flex-wrap gap-3">
                @if ($this->user()->isSuperAdmin())
                    <select wire:model.live="unitFilter" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900">
                        <option value="">Pilih RT</option>
                        @foreach ($this->units() as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                        @endforeach
                    </select>
                @endif
                <x-filament::input.wrapper class="grow">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="notVotedSearch" placeholder="Saring nama" />
                </x-filament::input.wrapper>
            </div>
            <ul class="mt-3 grid gap-x-6 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->notVoted() as $voter)
                    <li class="py-1">{{ $voter->name }} <span class="text-sm text-gray-500">· {{ $voter->address }}</span></li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
