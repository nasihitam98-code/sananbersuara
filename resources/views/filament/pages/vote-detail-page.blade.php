<x-filament-panels::page>
    @php($election = $this->openedElection())

    @if ($election === null)
        <x-filament::section>
            <div class="space-y-3">
                <p>Halaman ini menampilkan <strong>siapa memilih siapa</strong> untuk pemilihan yang sudah <strong>ditutup</strong>.</p>
                <p class="text-sm text-gray-500">Gunakan hanya untuk sengketa, pemeriksaan koreksi, atau audit. Setiap pembukaan dicatat dan diberitahukan.</p>
                @if ($this->availableElections()->isEmpty())
                    <p class="text-warning-600">Belum ada pemilihan yang ditutup (atau keterkaitannya sudah dihapus sesuai kebijakan retensi).</p>
                @else
                    @if ($this->openAction->isVisible()) {{ $this->openAction }} @endif
                @endif
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <p class="text-sm text-gray-500">Pemilihan</p>
                    <p class="font-bold">{{ $election->name }}</p>
                </div>
                <label class="text-sm">Surat suara
                    <select wire:model.live="ballotFilter" class="block rounded-lg border-gray-300 text-sm dark:bg-gray-900">
                        @foreach ($election->ballots as $ballot)
                            <option value="{{ $ballot->id }}">{{ $ballot->title }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm">RT
                    <select wire:model.live="unitFilter" class="block rounded-lg border-gray-300 text-sm dark:bg-gray-900">
                        <option value="">Semua RT</option>
                        @foreach ($this->unitOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <x-filament::button color="gray" wire:click="close">Tutup detail</x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500">
                        <tr><th class="py-1 pe-3">RT</th><th class="pe-3">No.</th><th class="pe-3">Nama pemilih</th><th class="pe-3">Pilihan</th><th class="pe-3">Putaran</th><th>Status suara</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($this->rows() as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-1 pe-3">{{ $row['unit'] }}</td>
                                <td class="pe-3 font-mono">{{ $row['number'] }}</td>
                                <td class="pe-3">{{ $row['name'] }}</td>
                                <td class="pe-3">{{ $row['choice'] }}</td>
                                <td class="pe-3">{{ $row['round'] }}</td>
                                <td>{{ $row['valid'] ? 'Sah' : 'Dibatalkan' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-3 text-gray-500">Tidak ada data untuk pilihan ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
