<x-filament-panels::page>
    @php($election = $this->election())

    @include('filament.pages.partials.election-picker', ['election' => $election, 'elections' => $this->availableElections()])

    @if ($election === null)
        <x-filament::section>
            Belum ada pemilihan Mode Dadakan yang ditugaskan kepada Anda sebagai Panitia.
        </x-filament::section>
    @else
        @if ($reissued)
            <x-filament::section>
                <div class="text-center space-y-2" role="status">
                    <p class="font-semibold">PIN BARU untuk {{ $reissued['name'] }} (No. {{ $reissued['number'] }})</p>
                    @if ($reissued['cancelled'] > 0)
                        <p class="text-danger-600">{{ $reissued['cancelled'] }} suara lama dibatalkan.</p>
                    @endif
                    <p class="font-mono font-black tracking-[0.4em] text-7xl text-primary-700 dark:text-primary-300">{{ $reissued['pin'] }}</p>
                    <p class="text-sm text-danger-600 font-semibold">Cetak kartu baru atau tulis di kertas baru. PIN hanya tampil sekali. PIN lama sudah tidak berlaku.</p>
                    <div class="mx-auto max-w-sm space-y-2">
                        @include('filament.pages.partials.pin-card-print', ['card' => [
                            'name' => $reissued['name'],
                            'number' => $reissued['number'],
                            'pin' => $reissued['pin'],
                            'election' => $election->name,
                            'note' => 'PIN BARU. PIN lama tidak berlaku.',
                        ]])
                        <x-filament::button color="success" wire:click="acknowledgeReissue" class="w-full">Sudah dicatat</x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @endif

        {{ $this->table }}
    @endif
</x-filament-panels::page>
