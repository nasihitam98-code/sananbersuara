{{--
    Pemilih gambar dari Media, seperti pustaka media WordPress: grid gambar kecil yang rapat, klik untuk memilih
    (klik lagi untuk batal), lalu rincian gambar terpilih (nama file, dipakai siapa) di bawah grid.
--}}
@php($photos ??= collect())
@php($unusedCount = $photos->filter(fn ($photo) => $photo->usageLabels() === [])->count())

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div x-data="{ q: '', filter: 'semua', state: $wire.$entangle(@js($getStatePath())) }" class="space-y-3">
        @if ($photos->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Media masih kosong. Unggah di atas, atau kumpulkan semua gambar dulu lewat menu <strong>Media</strong>.</p>
        @else
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::input.wrapper class="min-w-[12rem] grow">
                    <x-filament::input type="search" x-model="q" placeholder="Cari nama file…" />
                </x-filament::input.wrapper>
                <div class="flex overflow-hidden rounded-lg text-sm ring-1 ring-gray-200 dark:ring-white/10">
                    <button type="button" x-on:click="filter = 'semua'" x-bind:class="filter === 'semua' ? 'bg-primary-600 text-white' : 'hover:bg-gray-50 dark:hover:bg-white/5'" class="px-3 py-1.5">Semua ({{ $photos->count() }})</button>
                    <button type="button" x-on:click="filter = 'belum'" x-bind:class="filter === 'belum' ? 'bg-primary-600 text-white' : 'hover:bg-gray-50 dark:hover:bg-white/5'" class="px-3 py-1.5">Belum dipakai ({{ $unusedCount }})</button>
                </div>
            </div>

            <div class="grid grid-cols-4 gap-2 sm:grid-cols-6 md:grid-cols-8 xl:grid-cols-10">
                @foreach ($photos as $photo)
                    @php($usage = $photo->usageLabels())
                    <button
                        type="button"
                        x-show="(q === '' || @js(mb_strtolower($photo->original_name)).includes(q.toLowerCase())) && (filter === 'semua' || {{ $usage === [] ? 'true' : 'false' }})"
                        x-on:click="state = (state == {{ $photo->id }}) ? null : {{ $photo->id }}"
                        x-bind:class="state == {{ $photo->id }} ? 'ring-4 ring-primary-600' : 'ring-1 ring-gray-200 hover:ring-2 hover:ring-primary-400 dark:ring-white/10'"
                        x-bind:aria-pressed="state == {{ $photo->id }}"
                        title="{{ $photo->original_name }}{{ $usage !== [] ? ' · dipakai: '.implode(', ', $usage) : '' }}"
                        class="relative aspect-square overflow-hidden rounded-md bg-gray-100 transition dark:bg-white/5"
                    >
                        <img src="{{ $photo->previewUrl() }}" alt="{{ $photo->original_name }}" class="h-full w-full object-cover" loading="lazy">
                        @if ($usage !== [])
                            <span class="absolute bottom-1 left-1 rounded bg-gray-950/70 px-1.5 text-[10px] font-medium text-white">dipakai</span>
                        @endif
                        <span x-show="state == {{ $photo->id }}" class="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded bg-primary-600 text-xs font-bold text-white">✓</span>
                    </button>
                @endforeach
            </div>

            {{-- Rincian gambar terpilih (seperti "Attachment details" di WordPress). --}}
            @foreach ($photos as $photo)
                @php($usage = $photo->usageLabels())
                <div x-show="state == {{ $photo->id }}" x-cloak class="flex items-center gap-3 rounded-lg bg-gray-50 p-3 ring-1 ring-gray-200 dark:bg-white/5 dark:ring-white/10">
                    <img src="{{ $photo->previewUrl() }}" alt="" class="h-16 w-16 flex-none rounded object-cover">
                    <div class="min-w-0 text-sm">
                        <p class="truncate font-semibold" title="{{ $photo->original_name }}">Terpilih: {{ $photo->original_name }}</p>
                        <p class="text-gray-500 dark:text-gray-400">{{ $usage === [] ? 'Belum dipakai' : 'Dipakai: '.implode(', ', $usage) }}</p>
                    </div>
                    <button type="button" x-on:click="state = null" class="ms-auto flex-none text-sm text-gray-500 underline hover:text-gray-700 dark:text-gray-400">Batal pilih</button>
                </div>
            @endforeach
        @endif
    </div>
</x-dynamic-component>
