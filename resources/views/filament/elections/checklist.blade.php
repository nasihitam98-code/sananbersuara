@php($steps ??= [])

<x-filament::section heading="Persiapan" description="Kerjakan dari atas ke bawah. Tanda ✓ berarti langkah itu sudah beres.">
    <ol class="divide-y divide-gray-100 dark:divide-white/10">
        @foreach ($steps as $step)
            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
                <span @class([
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold',
                    'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-300' => $step['done'],
                    'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-300' => ! $step['done'],
                ])>{{ $step['done'] ? '✓' : $loop->iteration }}</span>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold">{{ $loop->iteration }}. {{ $step['title'] }}</p>
                    <p @class(['text-sm', 'text-gray-600 dark:text-gray-300' => $step['done'], 'text-warning-700 dark:text-warning-400' => ! $step['done']])>{{ $step['detail'] }}</p>
                </div>
                @if ($step['url'])
                    <x-filament::button tag="a" :href="$step['url']" size="sm" :color="$step['done'] ? 'gray' : 'primary'">
                        {{ $step['actionLabel'] }}
                    </x-filament::button>
                @endif
            </li>
        @endforeach
    </ol>
</x-filament::section>
