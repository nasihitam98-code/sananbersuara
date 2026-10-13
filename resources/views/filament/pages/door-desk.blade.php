<x-filament-panels::page>
    @php($election = $this->election())

    @include('filament.pages.partials.election-picker', ['election' => $election, 'elections' => $this->availableElections()])

    @if ($election === null)
        <x-filament::section>
            Meja Pintu dipakai saat pemilihan berstatus Siap atau Berlangsung, untuk petugas yang ditugaskan di pemilihan itu.
        </x-filament::section>
    @else
        @if ($issued)
            <x-filament::section>
                <div class="text-center space-y-3" role="status">
                    <p class="text-base text-gray-600 dark:text-gray-300">Kartu PIN dicetak otomatis. Berikan kertasnya kepada (atau tuliskan bila printer bermasalah):</p>
                    <p class="text-2xl font-bold">{{ $issued['name'] }}</p>
                    <p class="text-gray-600 dark:text-gray-300">No. hadir {{ $issued['number'] }}</p>
                    <p class="text-sm font-semibold uppercase tracking-wide text-gray-500">PIN</p>
                    <p class="font-mono font-black tracking-[0.4em] text-7xl text-primary-700 dark:text-primary-300">{{ $issued['pin'] }}</p>
                    <p class="text-sm text-danger-600 font-semibold">PIN hanya tampil sekali. Setelah ditekan "Sudah diberikan", PIN tidak bisa dilihat lagi.</p>
                    @include('filament.pages.partials.pin-card-print', ['card' => [
                        'name' => $issued['name'],
                        'number' => $issued['number'],
                        'pin' => $issued['pin'],
                        'election' => $election->name,
                    ], 'auto' => true])
                    <x-filament::button size="xl" color="primary" icon="heroicon-m-arrow-right" icon-position="after" wire:click="acknowledge" class="w-full">
                        Sudah diberikan, lanjut orang berikutnya
                    </x-filament::button>
                </div>
            </x-filament::section>
        @else
            <x-filament::section heading="Daftarkan yang hadir">
                <form wire:submit="register" class="space-y-4">
                    {{ $this->form }}

                    @if ($similarNames)
                        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-warning-800 dark:bg-warning-950 dark:text-warning-200" role="alert">
                            <p class="font-semibold">Nama yang sama sudah terdaftar:</p>
                            <ul class="list-disc ps-5">
                                @foreach ($similarNames as $similar)
                                    <li>{{ $similar }}</li>
                                @endforeach
                            </ul>
                            <p class="mt-2">Pastikan ini <strong>orang yang berbeda</strong>. Satu orang hanya boleh didata sekali.</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <x-filament::button type="submit" color="warning">Ya, orang berbeda. Daftarkan</x-filament::button>
                                <x-filament::button type="button" color="gray" wire:click="cancelDuplicate">Batal</x-filament::button>
                            </div>
                        </div>
                    @else
                        <x-filament::button type="submit" size="xl" class="w-full">
                            Daftarkan &amp; buat PIN
                        </x-filament::button>
                    @endif
                </form>
            </x-filament::section>
        @endif

        <x-filament::section heading="10 pendaftaran terakhir" collapsible>
            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($this->recentAttendees() as $attendee)
                    <li class="flex justify-between py-2">
                        <span><span class="font-mono text-gray-500">{{ $attendee->displayNumber() }}</span> · {{ $attendee->name }}</span>
                        <span class="text-sm text-gray-500">{{ $attendee->unit?->name }} {{ $attendee->is_late ? '· datang terlambat' : '' }}</span>
                    </li>
                @empty
                    <li class="py-2 text-gray-500">Belum ada yang didata.</li>
                @endforelse
            </ul>
        </x-filament::section>

        {{-- Agar kartu langsung keluar dari printer thermal tanpa jendela "Print" di setiap pendaftaran. --}}
        <x-filament::section heading="Printer thermal: cetak langsung tanpa jendela print" collapsible collapsed>
            <ol class="list-decimal space-y-2 ps-5 text-sm text-gray-700 dark:text-gray-300">
                <li>Pasang driver printer thermal (58 mm atau 80 mm) di laptop Meja Pintu, lalu jadikan <strong>printer default</strong> di Windows (Pengaturan → Printer).</li>
                <li>Buat shortcut Google Chrome khusus Meja Pintu: klik kanan shortcut Chrome → <em>Properties</em> → di kolom <em>Target</em>, tambahkan di paling belakang: <code class="rounded bg-gray-100 px-1 dark:bg-white/10">--kiosk-printing</code></li>
                <li>Tutup semua jendela Chrome, lalu buka panel lewat shortcut tadi. Setiap selesai mendaftarkan, kartu PIN langsung tercetak tanpa ditanya.</li>
                <li>Tanpa langkah di atas, jendela print tetap muncul otomatis: cukup tekan <strong>Enter</strong> / <strong>Print</strong>.</li>
            </ol>
        </x-filament::section>
    @endif
</x-filament-panels::page>
