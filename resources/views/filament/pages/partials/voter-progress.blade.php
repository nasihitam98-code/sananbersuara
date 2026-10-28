{{-- Daftar nama belum/sudah memilih (trait ShowsVoterProgress): dipakai Monitoring dan Partisipasi. Tanpa pilihan calon dan tanpa jam memilih. --}}
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
                        @foreach (\App\Models\Unit::query()->orderBy('sort')->get() as $unit)
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
                            <li class="py-1"><span class="me-2 inline-block w-8 text-right tabular-nums text-gray-400">{{ $loop->iteration }}</span>{{ $voter->name }}@if ($voter->rt_number)<span class="text-sm text-gray-500"> · No. absen {{ $voter->rt_number }}</span>@endif</li>
                        @endforeach
                    </ul>
                @endforeach
            @else
                <ul class="mt-3 grid gap-x-6 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($notVoted as $voter)
                        <li class="py-1"><span class="me-2 inline-block w-8 text-right tabular-nums text-gray-400">{{ $loop->iteration }}</span>{{ $voter->name }}@if ($voter->rt_number)<span class="text-sm text-gray-500"> · No. absen {{ $voter->rt_number }}</span>@endif</li>
                    @endforeach
                </ul>
            @endif

            @if ($notVoted->count() >= $this::NOT_VOTED_LIMIT)
                <p class="mt-3 text-sm text-gray-500">Menampilkan {{ $this::NOT_VOTED_LIMIT }} nama pertama. Pilih RT atau saring nama untuk melihat sisanya.</p>
            @endif
        </x-filament::section>
