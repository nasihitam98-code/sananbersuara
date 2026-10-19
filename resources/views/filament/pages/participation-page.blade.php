<x-filament-panels::page>
    @php($election = $this->election())

    @if ($election === null)
        <x-filament::section>Belum ada pemilihan Mode Resmi yang berjalan.</x-filament::section>
    @else
        <div wire:poll.5s data-auto-refresh="off" class="space-y-6">
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::badge size="lg" :color="$election->status->getColor()">{{ $election->name }} · {{ $election->status->getLabel() }}</x-filament::badge>
                <span class="text-sm text-gray-500">Hanya jumlah sudah/belum memilih. Angka per calon tidak ditampilkan selama berlangsung.</span>
            </div>

            @foreach ($this->table() as $block)
                <x-filament::section :heading="$block['title']">
                    @if ($block['total'])
                        <div class="mb-4">
                            <p class="text-4xl font-black tabular-nums">{{ $block['total']['voted'] }} <span class="text-lg font-normal text-gray-500">dari {{ $block['total']['eligible'] }} = {{ \Illuminate\Support\Number::percentage($block['total']['percent'], maxPrecision: 1) }}</span></p>
                            <div class="mt-2 h-4 w-full rounded-full bg-gray-100 dark:bg-white/10"><div class="h-4 rounded-full bg-primary-500" style="width: {{ $block['total']['percent'] }}%"></div></div>
                        </div>
                    @endif

                    <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-5">
                        @foreach ($block['units'] as $row)
                            {{-- Super Admin: klik kartu RT untuk melihat siapa yang belum memilih di RT itu. --}}
                            <div @if ($this->user()->isSuperAdmin()) wire:click="showUnit({{ $row['unit_id'] }})" role="button" title="Lihat yang belum memilih di {{ $row['unit'] }}" @endif
                                 class="rounded-xl border p-3 dark:border-white/10 {{ $this->user()->isSuperAdmin() ? 'cursor-pointer hover:border-primary-400' : '' }} {{ $this->unitFilter === $row['unit_id'] ? 'border-primary-500 ring-1 ring-primary-500' : 'border-gray-200' }}">
                                <p class="text-sm font-semibold">{{ $row['unit'] }}</p>
                                <p class="text-2xl font-black tabular-nums">{{ $row['voted'] }}<span class="text-sm font-normal text-gray-500"> / {{ $row['eligible'] }}</span></p>
                                <div class="mt-1 h-2 w-full rounded-full bg-gray-100 dark:bg-white/10"><div class="h-2 rounded-full bg-primary-500" style="width: {{ $row['percent'] }}%"></div></div>
                                <p class="mt-1 text-xs text-gray-500">{{ \Illuminate\Support\Number::percentage($row['percent'], maxPrecision: 1) }} · belum {{ $row['not_voted'] }}@if ($row['added_during_live']) · +{{ $row['added_during_live'] }} darurat @endif</p>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endforeach
        </div>

        @php($notVoted = $this->voterList())
        @php($counts = $this->voterCounts())
        @php($notVotedCount = $counts[$this->voterTab === 'sudah' ? 'sudah' : 'belum'])
        @php($allUnits = $this->user()->isSuperAdmin() && $this->unitFilter === null)
        <x-filament::section heading="Daftar pemilih" collapsible>
            {{-- Hanya nama sudah/belum, tanpa pilihan dan tanpa jam memilih. --}}
            <x-filament::tabs class="mb-3">
                <x-filament::tabs.item :active="$this->voterTab !== 'sudah'" wire:click="$set('voterTab', 'belum')">
                    Belum memilih ({{ $counts['belum'] }} orang)
                </x-filament::tabs.item>
                <x-filament::tabs.item :active="$this->voterTab === 'sudah'" wire:click="$set('voterTab', 'sudah')">
                    Sudah memilih ({{ $counts['sudah'] }} orang)
                </x-filament::tabs.item>
            </x-filament::tabs>

            <div class="flex flex-wrap gap-3">
                @if ($this->user()->isSuperAdmin())
                    <select wire:model.live="unitFilter" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900">
                        <option value="">Semua RT</option>
                        @foreach ($this->units() as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                        @endforeach
                    </select>
                @endif
                <x-filament::input.wrapper class="grow">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="notVotedSearch" placeholder="Saring nama" />
                </x-filament::input.wrapper>
            </div>

            @if ($notVoted->isEmpty())
                <p class="mt-3 text-sm text-gray-500">{{ $notVotedCount === 0 ? ($this->voterTab === 'sudah' ? 'Belum ada yang memilih.' : 'Semua pemilih sudah memilih.') : 'Tidak ada nama yang cocok.' }}</p>
            @elseif ($allUnits)
                {{-- Semua RT: dikelompokkan per RT agar mudah dibagikan ke Admin RT masing-masing. --}}
                @foreach ($notVoted->groupBy('unit_id')->sortBy(fn ($voters) => $voters->first()->unit?->sort) as $voters)
                    <h3 class="mt-4 text-sm font-semibold">{{ $voters->first()->unit?->name }} <span class="font-normal text-gray-500">· {{ $voters->count() }} orang</span></h3>
                    <ul class="grid gap-x-6 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($voters as $voter)
                            <li class="py-1">{{ $voter->name }} <span class="text-sm text-gray-500">· {{ $voter->address }}</span></li>
                        @endforeach
                    </ul>
                @endforeach
            @else
                <ul class="mt-3 grid gap-x-6 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($notVoted as $voter)
                        <li class="py-1">{{ $voter->name }} <span class="text-sm text-gray-500">· {{ $voter->address }}</span></li>
                    @endforeach
                </ul>
            @endif

            @if ($notVoted->count() >= \App\Filament\Pages\ParticipationPage::NOT_VOTED_LIMIT)
                <p class="mt-3 text-sm text-gray-500">Menampilkan {{ \App\Filament\Pages\ParticipationPage::NOT_VOTED_LIMIT }} nama pertama. Pilih RT atau saring nama untuk melihat sisanya.</p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
