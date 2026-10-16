{{--
    Tabel peserta yang sama di Ruang Kendali dan Meja Pintu: kartu cetak ulang, tab belum/sudah/semua, lalu tabelnya.
    Parameter: $election (pemilihan terpilih).
--}}
@if ($printCard)
    {{-- wire:key berganti tiap cetak ulang: elemen dibuat baru sehingga langsung mencetak. --}}
    <x-filament::section wire:key="print-card-{{ $printCard['token'] }}">
        <div class="mx-auto max-w-sm space-y-2 text-center" role="status">
            <p class="font-semibold">Cetak ulang: {{ $printCard['name'] }} (No. {{ $printCard['number'] }})</p>
            @if ($printCard['note'])
                <p class="font-semibold text-warning-700 dark:text-warning-400">{{ $printCard['note'] }}</p>
            @endif
            <p class="font-mono text-6xl font-black tracking-[0.4em] text-primary-700 dark:text-primary-300">{{ $printCard['pin'] }}</p>
            @include('filament.pages.partials.pin-card-print', ['card' => [
                'name' => $printCard['name'],
                'number' => $printCard['number'],
                'pin' => $printCard['pin'],
                'election' => $election->name,
                'note' => $printCard['note'],
            ], 'force' => true])
            <x-filament::button color="primary" wire:click="closePrintCard" class="w-full">Selesai</x-filament::button>
        </div>
    </x-filament::section>
@endif

@php($tabCounts = $this->participantTabCounts())
<div class="space-y-3">
    <x-filament::tabs label="Peserta">
        @foreach (['belum' => 'Belum memilih', 'sudah' => 'Sudah memilih', 'semua' => 'Semua'] as $tabKey => $tabLabel)
            <x-filament::tabs.item
                :active="$participantTab === $tabKey"
                :badge="$tabCounts[$tabKey]"
                :badge-color="$tabKey === 'sudah' ? 'success' : ($tabKey === 'belum' ? 'warning' : 'gray')"
                wire:click="setParticipantTab('{{ $tabKey }}')"
            >
                {{ $tabLabel }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}
</div>
