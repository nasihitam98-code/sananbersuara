{{-- Logo panel: ikon kotak suara + nama aplikasi (dari Pengaturan Tampilan). --}}
<div class="flex items-center gap-2.5">
    <span class="grid h-9 w-9 place-items-center rounded-xl bg-primary-600 text-white shadow-sm">
        <x-filament::icon icon="heroicon-s-check-badge" class="h-5 w-5" />
    </span>
    <span class="text-lg font-bold tracking-tight text-gray-950 dark:text-white">{{ config('app.name') }}</span>
</div>
