<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="space-y-1 text-sm">
                <p>Unggah semua foto calon ke galeri, lalu pasang ke calon: lewat <strong>Pasang ke calon…</strong> di bawah foto, tombol <strong>Foto</strong> di daftar Calon, atau <strong>Pasang otomatis</strong> bila nama file berisi nomor/nama calon.</p>
                <p class="text-gray-500 dark:text-gray-400">Foto galeri hanya bisa dilihat Super Admin. Hapus foto yang tidak dipakai setelah selesai.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($this->uploadAction->isVisible()) {{ $this->uploadAction }} @endif
                @if ($this->autoAssignAction->isVisible()) {{ $this->autoAssignAction }} @endif
                @if ($this->deleteUnusedAction->isVisible()) {{ $this->deleteUnusedAction }} @endif
            </div>
        </div>
    </x-filament::section>

    <div class="flex items-center gap-3 text-sm">
        <label class="flex items-center gap-2">
            <x-filament::input.checkbox wire:model.live="onlyUnused" />
            Tampilkan hanya yang belum dipakai ({{ $this->unusedCount() }})
        </label>
    </div>

    @php($photos = $this->photos())

    @if ($photos->isEmpty())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">Galeri masih kosong. Klik <strong>Unggah ke galeri</strong>.</p>
        </x-filament::section>
    @else
        <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
            @foreach ($photos as $photo)
                <li class="flex flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <a href="{{ $photo->previewUrl('full') }}" target="_blank" class="block aspect-square bg-gray-100 dark:bg-white/5">
                        <img src="{{ $photo->previewUrl() }}" alt="{{ $photo->original_name }}" class="h-full w-full object-cover" loading="lazy">
                    </a>
                    <div class="flex flex-1 flex-col gap-2 p-3">
                        <p class="truncate text-sm font-semibold" title="{{ $photo->original_name }}">{{ $photo->original_name }}</p>
                        @if ($photo->candidates->isNotEmpty())
                            @foreach ($photo->candidates as $candidate)
                                <x-filament::badge color="success" size="sm">Dipakai: {{ $candidate->displayNumber() }} {{ $candidate->name }}</x-filament::badge>
                            @endforeach
                        @else
                            <x-filament::badge color="gray" size="sm">Belum dipakai</x-filament::badge>
                        @endif
                        <div class="mt-auto flex flex-wrap gap-2 pt-1">
                            {{ ($this->assignAction)(['photo' => $photo->public_id]) }}
                            {{ ($this->deleteAction)(['photo' => $photo->public_id]) }}
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament-panels::page>
