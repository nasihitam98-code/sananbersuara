<x-filament-panels::page>
    @php($election = $this->election())

    @include('filament.pages.partials.election-picker', ['election' => $election, 'elections' => $this->availableElections()])

    @if ($election === null)
        <x-filament::section>
            Belum ada pemilihan Mode Dadakan yang ditugaskan kepada Anda sebagai Panitia.
        </x-filament::section>
    @else
        {{ $this->table }}
    @endif
</x-filament-panels::page>
