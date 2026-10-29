{{-- Daftar nama belum/sudah memilih (trait ShowsVoterProgress): dipakai Monitoring dan Partisipasi. Tanpa pilihan calon dan tanpa jam memilih. --}}
        @php($notVoted = $this->voterList())
        @php($counts = $this->voterCounts())
        @php($notVotedCount = $counts[$this->voterTab === 'sudah' ? 'sudah' : 'belum'])
        <x-filament::section heading="Daftar pemilih" collapsible>
            {{-- Hanya nama sudah/belum, tanpa pilihan dan tanpa jam memilih. --}}
            <x-filament::tabs class="mb-4">
                <x-filament::tabs.item :active="$this->voterTab !== 'sudah'" wire:click="$set('voterTab', 'belum')" icon="heroicon-m-clock" :badge="$counts['belum'].' orang'" badge-color="warning">
                    Belum memilih
                </x-filament::tabs.item>
                <x-filament::tabs.item :active="$this->voterTab === 'sudah'" wire:click="$set('voterTab', 'sudah')" icon="heroicon-m-check-circle" :badge="$counts['sudah'].' orang'" badge-color="success">
                    Sudah memilih
                </x-filament::tabs.item>
            </x-filament::tabs>

            <div class="flex flex-wrap items-center gap-3">
                @if ($this->user()->isSuperAdmin())
                    <x-filament::input.wrapper class="w-44">
                        <x-filament::input.select wire:model.live="unitFilter">
                            <option value="">Semua RT</option>
                            @foreach (\App\Models\Unit::query()->orderBy('sort')->get() as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @endif
                <x-filament::input.wrapper class="min-w-[14rem] grow" prefix-icon="heroicon-m-magnifying-glass">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="notVotedSearch" placeholder="Saring nama" />
                </x-filament::input.wrapper>
            </div>

            @if ($notVoted->isEmpty())
                <p class="mt-4 text-sm text-gray-500">{{ $notVotedCount === 0 ? ($this->voterTab === 'sudah' ? 'Belum ada yang memilih.' : 'Semua pemilih sudah memilih.') : 'Tidak ada nama yang cocok.' }}</p>
            @else
                {{-- Dikelompokkan per RT (kartu sendiri) agar mudah dibagikan ke Admin RT masing-masing. --}}
                <div class="mt-4 space-y-4">
                    @foreach ($notVoted->groupBy('unit_id')->sortBy(fn ($voters) => $voters->first()->unit?->sort) as $voters)
                        <div class="overflow-hidden rounded-xl ring-1 ring-gray-200 dark:ring-white/10">
                            <div class="flex items-center justify-between gap-3 bg-gray-50 px-4 py-2 dark:bg-white/5">
                                <h3 class="font-semibold">{{ $voters->first()->unit?->name }}</h3>
                                <x-filament::badge :color="$this->voterTab === 'sudah' ? 'success' : 'warning'">{{ $voters->count() }} orang</x-filament::badge>
                            </div>
                            <div class="px-4 py-2">
                                @include('filament.pages.partials.voter-progress-list', ['voters' => $voters])
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($notVoted->count() >= $this::NOT_VOTED_LIMIT)
                <p class="mt-3 text-sm text-gray-500">Menampilkan {{ $this::NOT_VOTED_LIMIT }} nama pertama. Pilih RT atau saring nama untuk melihat sisanya.</p>
            @endif
        </x-filament::section>
