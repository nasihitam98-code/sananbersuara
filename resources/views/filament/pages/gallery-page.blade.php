<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="space-y-1 text-sm">
                <p>Satu tempat untuk semua gambar: foto calon dan logo. Gambar yang diunggah dari tombol <strong>Foto</strong> di daftar Calon atau dari <strong>Logo</strong> di Pengaturan Tampilan juga masuk ke sini.</p>
                <p>Klik satu gambar: rinciannya tampil di panel samping, dengan tombol <strong>Pasang</strong> (ke calon), <strong>Jadikan logo</strong>, dan <strong>Hapus gambar</strong>. Atau <strong>Pasang otomatis</strong> bila nama file berisi nomor/nama calon.</p>
                <p class="text-gray-500 dark:text-gray-400">Gambar di Media hanya bisa dilihat Super Admin; baru tampil ke warga setelah dipasang ke calon atau dijadikan logo. Hapus yang tidak dipakai setelah selesai.</p>
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
            <p class="text-sm text-gray-500 dark:text-gray-400">Media masih kosong. Klik <strong>Unggah ke Media</strong>.</p>
        </x-filament::section>
    @else
        {{-- Seperti pustaka media WordPress: grid gambar kecil; klik satu gambar untuk melihat rincian dan tindakannya di panel samping. --}}
        @php($selected = $this->selected())
        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <ul class="grid grid-cols-3 gap-2 sm:grid-cols-5 md:grid-cols-6 xl:grid-cols-8">
                @foreach ($photos as $photo)
                    @php($usage = $photo->usageLabels())
                    @php($isSelected = $selected?->is($photo) ?? false)
                    <li>
                        <button
                            type="button"
                            wire:click="selectPhoto('{{ $photo->public_id }}')"
                            title="{{ $photo->original_name }}{{ $usage !== [] ? ' · dipakai: '.implode(', ', $usage) : '' }}"
                            aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                            class="relative block aspect-square w-full overflow-hidden rounded-md bg-gray-100 transition dark:bg-white/5 {{ $isSelected ? 'ring-4 ring-primary-600' : 'ring-1 ring-gray-200 hover:ring-2 hover:ring-primary-400 dark:ring-white/10' }}"
                        >
                            <img src="{{ $photo->previewUrl() }}" alt="{{ $photo->original_name }}" class="h-full w-full object-cover" loading="lazy">
                            @if ($photo->isLogo())
                                <span class="absolute bottom-1 left-1 rounded bg-info-600 px-1.5 text-[10px] font-medium text-white">logo</span>
                            @elseif ($usage !== [])
                                <span class="absolute bottom-1 left-1 rounded bg-gray-950/70 px-1.5 text-[10px] font-medium text-white">dipakai</span>
                            @endif
                            @if ($isSelected)
                                <span class="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded bg-primary-600 text-xs font-bold text-white">✓</span>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>

            {{-- Panel rincian (di HP tampil di atas grid agar langsung terlihat setelah diklik). --}}
            <aside class="order-first rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 lg:sticky lg:top-4 lg:order-none dark:bg-gray-900 dark:ring-white/10">
                @if ($selected === null)
                    <p class="text-sm text-gray-500 dark:text-gray-400">Klik salah satu gambar untuk melihat rinciannya, memasangnya ke calon, menjadikannya logo, atau menghapusnya.</p>
                @else
                    @php($usage = $selected->usageLabels())
                    <a href="{{ $selected->previewUrl('full') }}" target="_blank" title="Buka ukuran penuh" class="block overflow-hidden rounded-lg bg-gray-100 dark:bg-white/5">
                        <img src="{{ $selected->previewUrl() }}" alt="{{ $selected->original_name }}" class="aspect-square w-full object-contain">
                    </a>
                    <p class="mt-3 break-words text-sm font-semibold">{{ $selected->original_name }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        @if ($selected->width && $selected->height) {{ $selected->width }} × {{ $selected->height }} px · @endif
                        diunggah {{ $selected->created_at?->translatedFormat('d M Y H:i') }}@if ($selected->uploader) oleh {{ $selected->uploader->name }}@endif
                    </p>
                    <div class="mt-3 flex flex-wrap gap-1">
                        @forelse ($usage as $label)
                            <x-filament::badge :color="$label === 'Logo' ? 'info' : 'success'" size="sm">Dipakai: {{ $label }}</x-filament::badge>
                        @empty
                            <x-filament::badge color="gray" size="sm">Belum dipakai</x-filament::badge>
                        @endforelse
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4 dark:border-white/10">
                        @if (($this->assignAction)(['photo' => $selected->public_id])->isVisible()) {{ ($this->assignAction)(['photo' => $selected->public_id]) }} @endif
                        @unless ($selected->isLogo())
                            {{ ($this->useAsLogoAction)(['photo' => $selected->public_id]) }}
                        @endunless
                        <x-filament::link :href="$selected->previewUrl('full')" target="_blank" size="sm">Ukuran penuh</x-filament::link>
                        @if (($this->deleteAction)(['photo' => $selected->public_id])->isVisible()) {{ ($this->deleteAction)(['photo' => $selected->public_id]) }} @endif
                    </div>
                @endif
            </aside>
        </div>
    @endif
</x-filament-panels::page>
