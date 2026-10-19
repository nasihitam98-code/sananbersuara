{{--
    Di halaman login: laptop ini sudah terpasang sebagai Meja RT (Mode Resmi), agar petugas tahu pemasangan berhasil
    dan login dengan akun Admin RT yang sesuai.
--}}
@php($desk = app(\App\Services\Devices\DeviceManager::class)->authenticate(request(), \App\Enums\DeviceKind::Meja))
@if ($desk)
    <div class="rounded-xl bg-success-50 p-4 text-sm text-success-800 ring-1 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300" role="status">
        <p class="font-semibold">✓ Laptop ini terpasang sebagai {{ $desk->name() }}</p>
        <p class="mt-1">{{ $desk->election->name }}. Login dengan akun petugas {{ $desk->unit->name }} untuk membuka Meja Izin.</p>
    </div>
@endif
