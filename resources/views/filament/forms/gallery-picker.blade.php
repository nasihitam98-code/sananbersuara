@php($photos ??= collect())

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div x-data="{ q: '', state: $wire.$entangle(@js($getStatePath())) }" class="space-y-3">
        @if ($photos->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Galeri masih kosong. Unggah dulu lewat menu <strong>Galeri Foto</strong>.</p>
        @else
            <x-filament::input.wrapper>
                <x-filament::input type="search" x-model="q" placeholder="Cari nama file…" />
            </x-filament::input.wrapper>

            <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6">
                @foreach ($photos as $photo)
                    <button
                        type="button"
                        x-show="q === '' || @js(mb_strtolower($photo->original_name)).includes(q.toLowerCase())"
                        x-on:click="state = {{ $photo->id }}"
                        x-bind:class="state == {{ $photo->id }} ? 'ring-2 ring-primary-600 bg-primary-50 dark:bg-primary-500/10' : 'ring-1 ring-gray-200 hover:ring-primary-400 dark:ring-white/10'"
                        x-bind:aria-pressed="state == {{ $photo->id }}"
                        class="rounded-xl p-1.5 text-left transition"
                    >
                        <span class="relative block">
                            <img src="{{ $photo->previewUrl() }}" alt="{{ $photo->original_name }}" class="aspect-square w-full rounded-lg object-cover" loading="lazy">
                            <span x-show="state == {{ $photo->id }}" class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-primary-600 text-sm font-bold text-white">✓</span>
                        </span>
                        <span class="mt-1 block truncate text-xs font-medium" title="{{ $photo->original_name }}">{{ $photo->original_name }}</span>
                        @if ($photo->candidates->isNotEmpty())
                            <span class="block truncate text-[11px] text-gray-500 dark:text-gray-400">dipakai {{ $photo->candidates->map(fn ($candidate) => $candidate->displayNumber())->implode(', ') }}</span>
                        @else
                            <span class="block text-[11px] text-success-600 dark:text-success-400">belum dipakai</span>
                        @endif
                    </button>
                @endforeach
            </div>
        @endif
    </div>
</x-dynamic-component>
