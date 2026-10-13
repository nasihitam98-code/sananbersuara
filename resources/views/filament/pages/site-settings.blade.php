<x-filament-panels::page>
    {{-- Teks: diisi lalu Simpan. --}}
    <form wire:submit="save" class="space-y-4">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit">Simpan teks</x-filament::button>
        </div>
    </form>

    {{-- Logo: kartu sendiri; Unggah/Ganti dan Hapus langsung tersimpan (tidak ikut tombol Simpan teks). --}}
    @php($logo = \App\Models\AppSetting::logoUrl())
    <x-filament::section heading="Logo" description="Tampil di header halaman publik, panel admin, halaman login, HP pemilih, laptop bilik, layar proyektor, dan ikon tab browser.">
        <div class="flex flex-wrap items-center gap-5">
            <img src="{{ \App\Models\AppSetting::faviconUrl() }}" alt="Logo yang dipakai sekarang" class="h-20 w-20 shrink-0 rounded-xl bg-white object-contain p-1.5 ring-1 ring-gray-200 dark:ring-white/10">
            <div class="min-w-0 flex-1">
                <p class="font-semibold text-gray-950 dark:text-white">{{ $logo ? 'Memakai logo yang diunggah' : 'Memakai logo bawaan' }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $logo ? 'Ganti dengan logo lain, atau hapus untuk kembali ke kotak merah bercentang.' : 'Kotak merah bercentang. Unggah logo RW/kelurahan untuk menggantinya.' }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    {{ $this->uploadLogoAction }}
                    {{-- Syarat visible() aksi tidak dicek saat dirender langsung, jadi disaring di sini. --}}
                    @if ($logo)
                        {{ $this->removeLogoAction }}
                    @endif
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
