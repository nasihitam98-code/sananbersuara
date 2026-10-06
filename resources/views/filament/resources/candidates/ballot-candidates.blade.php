@php($limit = $ballot->max_candidates)

<x-filament::section
    :heading="'Sudah ada di surat suara ini: '.$candidates->count().' calon'.($limit ? ' (batas '.$limit.')' : '')"
    :description="$ballot->election->name.' — '.$ballot->title"
>
    @if ($needsUnit)
        <p class="text-sm text-gray-500 dark:text-gray-400">Pilih RT calon di atas untuk melihat calon RT tersebut.</p>
    @elseif ($candidates->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada calon. Calon yang Anda simpan akan muncul di sini.</p>
    @else
        <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($candidates as $candidate)
                @php($photo = $candidate->photoUrl('thumb'))
                <li class="flex items-center gap-3 rounded-lg p-2 ring-1 ring-gray-950/5 dark:ring-white/10">
                    @if ($photo)
                        <img src="{{ $photo }}" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover">
                    @else
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-sm font-bold text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $candidate->initials() }}</span>
                    @endif
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">Nomor {{ $candidate->displayNumber() }}{{ $candidate->originLabel() ? ' · Asal '.$candidate->originLabel() : '' }}</p>
                        <x-filament::link :href="\App\Filament\Resources\Candidates\CandidateResource::getUrl('edit', ['record' => $candidate])" class="truncate">
                            {{ $candidate->name }}
                        </x-filament::link>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament::section>
