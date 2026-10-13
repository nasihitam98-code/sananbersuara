{{-- Logo panel: kotak merah bertanda centang (sama dengan header portal) + nama aplikasi (dari Pengaturan Tampilan). --}}
<div class="flex items-center gap-2.5">
    <span class="grid h-9 w-9 place-items-center rounded-lg bg-red-600 text-white shadow-sm">
        <x-filament::icon icon="heroicon-s-check-badge" class="h-5 w-5" />
    </span>
    <span class="fi-brand-text text-lg font-black tracking-tight text-gray-950 dark:text-white">{{ config('app.name') }}</span>
</div>
