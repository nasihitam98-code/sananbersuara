{{-- Pratinjau posisi foto: bingkai tegak 4:5 seperti kartu halaman publik, dan bulat seperti di bilik. --}}
<div class="flex flex-wrap items-end gap-6">
    <figure class="text-center">
        <img src="{{ $url }}" alt="" class="h-56 w-44 rounded-2xl object-cover ring-2 ring-gray-200" style="object-position: center {{ $focus }}%">
        <figcaption class="mt-1 text-xs text-gray-500">Kartu halaman publik</figcaption>
    </figure>
    <figure class="text-center">
        <img src="{{ $url }}" alt="" class="h-28 w-28 rounded-full object-cover ring-2 ring-gray-200" style="object-position: center {{ $focus }}%">
        <figcaption class="mt-1 text-xs text-gray-500">Bilik</figcaption>
    </figure>
</div>
