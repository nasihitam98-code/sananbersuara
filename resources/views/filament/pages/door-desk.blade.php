<x-filament-panels::page>
    @php($election = $this->election())

    @include('filament.pages.partials.election-picker', ['election' => $election, 'elections' => $this->availableElections()])

    @if ($election === null)
        <x-filament::section>
            Belum ada pemilihan Mode Dadakan berstatus Siap/Berlangsung yang ditugaskan kepada Anda.
        </x-filament::section>
    @else
        @if ($issued)
            <x-filament::section>
                <div class="text-center space-y-3" role="status">
                    <p class="text-base text-gray-600 dark:text-gray-300">Tuliskan di kertas dan berikan kepada:</p>
                    <p class="text-2xl font-bold">{{ $issued['name'] }}</p>
                    <p class="text-gray-600 dark:text-gray-300">No. hadir {{ $issued['number'] }}</p>
                    <p class="text-sm font-semibold uppercase tracking-wide text-gray-500">PIN</p>
                    <p class="font-mono font-black tracking-[0.4em] text-7xl text-primary-700 dark:text-primary-300">{{ $issued['pin'] }}</p>
                    <p class="text-sm text-danger-600 font-semibold">PIN hanya tampil sekali. Setelah ditekan "Sudah dicatat", PIN tidak bisa dilihat lagi.</p>
                    <x-filament::button size="xl" color="success" wire:click="acknowledge" class="w-full">
                        Sudah dicatat, lanjut orang berikutnya
                    </x-filament::button>
                </div>
            </x-filament::section>
        @else
            <x-filament::section heading="Daftarkan yang hadir">
                <form wire:submit="register" class="space-y-4">
                    {{ $this->form }}

                    @if ($similarNames)
                        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-warning-800 dark:bg-warning-950 dark:text-warning-200" role="alert">
                            <p class="font-semibold">Nama yang sama sudah terdaftar:</p>
                            <ul class="list-disc ps-5">
                                @foreach ($similarNames as $similar)
                                    <li>{{ $similar }}</li>
                                @endforeach
                            </ul>
                            <p class="mt-2">Pastikan ini <strong>orang yang berbeda</strong>. Satu orang hanya boleh didata sekali.</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <x-filament::button type="submit" color="warning">Ya, orang berbeda. Daftarkan</x-filament::button>
                                <x-filament::button type="button" color="gray" wire:click="cancelDuplicate">Batal</x-filament::button>
                            </div>
                        </div>
                    @else
                        <x-filament::button type="submit" size="xl" class="w-full">
                            Daftarkan &amp; buat PIN
                        </x-filament::button>
                    @endif
                </form>
            </x-filament::section>
        @endif

        <x-filament::section heading="10 pendaftaran terakhir" collapsible>
            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($this->recentAttendees() as $attendee)
                    <li class="flex justify-between py-2">
                        <span><span class="font-mono text-gray-500">{{ $attendee->displayNumber() }}</span> · {{ $attendee->name }}</span>
                        <span class="text-sm text-gray-500">{{ $attendee->unit?->name }} {{ $attendee->is_late ? '· datang terlambat' : '' }}</span>
                    </li>
                @empty
                    <li class="py-2 text-gray-500">Belum ada yang didata.</li>
                @endforelse
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
