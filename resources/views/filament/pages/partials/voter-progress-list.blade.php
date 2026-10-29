{{-- Kolom nama rapi: nomor berjalan, nama, No. absen di kanan. Jumlah kolom menyesuaikan lebar layar. $voters --}}
<ol class="grid grid-cols-[repeat(auto-fill,minmax(15rem,1fr))] gap-x-6">
    @foreach ($voters as $voter)
        <li class="flex items-center gap-2 border-b border-gray-100 py-1.5 dark:border-white/5">
            <span class="w-7 flex-none text-right text-xs tabular-nums text-gray-400">{{ $loop->iteration }}</span>
            <span class="min-w-0 grow truncate font-medium" title="{{ $voter->name }}">{{ $voter->name }}</span>
            @if ($voter->rt_number)
                <span class="flex-none rounded bg-gray-100 px-1.5 text-xs tabular-nums text-gray-600 dark:bg-white/10 dark:text-gray-300" title="No. absen">No. {{ $voter->rt_number }}</span>
            @endif
        </li>
    @endforeach
</ol>
