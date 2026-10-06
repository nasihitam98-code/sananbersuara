<x-filament-panels::page>
    @if ($rows === [])
        <x-filament::section>
            <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">
                Kolom template: <strong>nama, rt, alamat</strong> (wajib), jenis_kelamin (L/P), tanggal_lahir (31-12-1970), nik (16 digit), no_hp.
                Maksimal {{ \App\Services\Voters\VoterImport::MAX_ROWS }} baris, 5 MB.
                <a class="text-primary-600 underline" href="{{ route('voters.template') }}">Unduh template</a>
            </p>
            <form wire:submit="preview" class="space-y-4">
                {{ $this->form }}
                <x-filament::button type="submit">Periksa file</x-filament::button>
            </form>
        </x-filament::section>
    @else
        @php($summary = $this->summary())
        <x-filament::section>
            <div class="flex flex-wrap gap-6 text-sm">
                <span>Total baris: <strong>{{ $summary['total'] }}</strong></span>
                <span class="text-success-600">Siap diimpor: <strong>{{ $summary['valid'] }}</strong></span>
                <span class="text-warning-600">Nama sama (perlu konfirmasi): <strong>{{ $summary['warnings'] }}</strong></span>
                <span class="text-danger-600">Error (dilewati): <strong>{{ $summary['errors'] }}</strong></span>
            </div>

            @if ($summary['warnings'] > 0)
                <label class="mt-4 flex items-start gap-2 text-sm">
                    <input type="checkbox" wire:model="includeWarnings" class="mt-1 rounded">
                    <span>Saya sudah memastikan baris "nama sama" adalah <strong>orang yang berbeda</strong>, ikut impor.</span>
                </label>
            @endif

            <div class="mt-4 flex flex-wrap gap-2">
                <x-filament::button wire:click="confirmImport" wire:confirm="Impor data ini? Baris error tidak akan disimpan." :disabled="$summary['valid'] + $summary['warnings'] === 0">
                    Konfirmasi import
                </x-filament::button>
                <x-filament::button color="gray" wire:click="resetPreview">Batal / ganti file</x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section heading="Pratinjau per baris">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500">
                        <tr><th class="py-1 pe-3">Baris</th><th class="pe-3">Nama</th><th class="pe-3">RT</th><th class="pe-3">Alamat</th><th>Keterangan</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-100 align-top dark:border-white/5">
                                <td class="py-1 pe-3 tabular-nums">{{ $row['line'] }}</td>
                                <td class="pe-3">{{ $row['name'] }}</td>
                                <td class="pe-3">{{ $row['unit'] }}</td>
                                <td class="pe-3">{{ $row['address'] }}</td>
                                <td>
                                    @foreach ($row['errors'] as $error)
                                        <p class="text-danger-600">✗ {{ $error }}</p>
                                    @endforeach
                                    @foreach ($row['warnings'] as $warning)
                                        <p class="text-warning-600">⚠ {{ $warning }}</p>
                                    @endforeach
                                    @if ($row['errors'] === [] && $row['warnings'] === [])
                                        <p class="text-success-600">✓ Siap</p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
