{{-- Logo panel: logo dari Pengaturan Tampilan, atau kotak merah bercentang bawaan (sama dengan portal), + nama aplikasi. --}}
@php($logo = \App\Models\AppSetting::logoUrl())
<div class="flex items-center gap-2.5">
    @if ($logo)
        <img src="{{ $logo }}" alt="" class="h-9 w-9 shrink-0 rounded-lg bg-white object-contain p-0.5 shadow-sm">
    @else
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-red-600 text-white shadow-sm">
            <x-filament::icon icon="heroicon-s-check-badge" class="h-5 w-5" />
        </span>
    @endif
    <span class="fi-brand-text text-lg font-black tracking-tight text-gray-950 dark:text-white">{{ config('app.name') }}</span>
</div>
