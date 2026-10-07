<x-filament-panels::page>
    @php($election = $this->election())

    @include('filament.pages.partials.election-picker', ['election' => $election, 'elections' => $this->availableElections()])

    @if ($election === null)
        <x-filament::section>
            Belum ada pemilihan Mode Dadakan yang ditugaskan kepada Anda sebagai Panitia.
        </x-filament::section>
    @else
        @php($d = $this->dashboard())

        <div wire:poll.3s class="space-y-6">
            @if ($reissued)
                <x-filament::section>
                    <div class="text-center space-y-2" role="status">
                        <p class="font-semibold">PIN BARU untuk {{ $reissued['name'] }} (No. {{ $reissued['number'] }})</p>
                        @if ($reissued['cancelled'] > 0)
                            <p class="text-danger-600">{{ $reissued['cancelled'] }} suara lama dibatalkan.</p>
                        @endif
                        <p class="font-mono font-black tracking-[0.4em] text-7xl text-primary-700 dark:text-primary-300">{{ $reissued['pin'] }}</p>
                        <p class="text-sm text-danger-600 font-semibold">Cetak kartu baru atau tulis di kertas baru. PIN hanya tampil sekali. PIN lama sudah tidak berlaku.</p>
                        <div class="mx-auto max-w-sm space-y-2">
                            @include('filament.pages.partials.pin-card-print', ['card' => [
                                'name' => $reissued['name'],
                                'number' => $reissued['number'],
                                'pin' => $reissued['pin'],
                                'election' => $election->name,
                                'note' => 'PIN BARU. PIN lama tidak berlaku.',
                            ]])
                            <x-filament::button color="success" wire:click="acknowledgeReissue" class="w-full">Sudah dicatat</x-filament::button>
                        </div>
                    </div>
                </x-filament::section>
            @endif

            {{-- Status gelombang --}}
            <x-filament::section>
                <div class="grid gap-6 md:grid-cols-3 items-center">
                    <div>
                        <p class="text-sm text-gray-500">Status pemilihan</p>
                        <x-filament::badge size="lg" :color="$election->status->getColor()">{{ $election->status->getLabel() }}</x-filament::badge>
                        <p class="mt-2 text-sm text-gray-500">Putaran {{ $d['round']?->number ?? '-' }}</p>
                    </div>
                    <div class="text-center">
                        @if ($d['wave'])
                            <p class="text-lg font-semibold">{{ $d['wave']->displayName() }}</p>
                            <p class="text-6xl font-black tabular-nums {{ ($d['status']['remaining'] ?? 999) <= 30 ? 'text-danger-600' : 'text-primary-700 dark:text-primary-300' }}">
                                @if ($d['status']['remaining'] === null)
                                    Tanpa timer
                                @else
                                    {{ sprintf('%02d:%02d', intdiv($d['status']['remaining'], 60), $d['status']['remaining'] % 60) }}
                                @endif
                            </p>
                            <p class="text-sm font-semibold">{{ $d['status']['state'] === 'paused' ? 'DIJEDA' : 'VOTING DIBUKA' }}</p>
                        @else
                            <p class="text-3xl font-bold text-gray-500">Voting tertutup</p>
                            <p class="text-sm text-gray-500">HP warga menampilkan "Menunggu pemungutan dibuka".</p>
                            @if (($d['round']?->waves()->exists() ?? false) && $d['participation']['not_voted'] > 0 && $election->status === \App\Enums\ElectionStatus::Berlangsung)
                                <p class="mt-2 text-sm font-semibold text-warning-600">{{ $d['participation']['not_voted'] }} orang belum memilih. Tekan BUKA VOTING untuk sesi berikutnya; yang sudah memilih tidak bisa memilih lagi.</p>
                            @endif
                        @endif
                    </div>
                    <div class="flex flex-col gap-2">
                        @if ($this->startElectionAction->isVisible()) {{ $this->startElectionAction }} @endif
                        @if ($this->openWaveAction->isVisible()) {{ $this->openWaveAction }} @endif
                        @if ($this->extendWaveAction->isVisible()) {{ $this->extendWaveAction }} @endif
                        @if ($this->closeWaveAction->isVisible()) {{ $this->closeWaveAction }} @endif
                        @if ($this->closeElectionAction->isVisible()) {{ $this->closeElectionAction }} @endif
                        @if ($election->status === \App\Enums\ElectionStatus::Ditutup && \App\Filament\Pages\ResultScreen::canAccess())
                            <x-filament::button tag="a" size="xl" icon="heroicon-o-chart-bar" :href="\App\Filament\Pages\ResultScreen::getUrl(['pemilihan' => $election->public_id])">
                                Buka Layar Hasil
                            </x-filament::button>
                            <p class="text-sm text-gray-500">Pemilihan sudah ditutup. Tampilkan hasil di proyektor dari Layar Hasil.</p>
                        @endif
                        @if ($election->status === \App\Enums\ElectionStatus::Ready)
                            <p class="text-sm text-warning-600">
                                {{ auth()->user()->isSuperAdmin()
                                    ? 'Langkah 1: tekan Mulai Pemilihan. Langkah 2: tombol BUKA VOTING muncul di sini.'
                                    : 'Pemilihan belum dimulai. Tunggu Super Admin menekan "Mulai Pemilihan"; tombol BUKA VOTING lalu muncul di sini.' }}
                            </p>
                        @endif
                    </div>
                </div>
            </x-filament::section>

            {{-- Partisipasi (tanpa angka per kandidat) --}}
            @php($p = $d['participation'])
            <div class="grid gap-4 grid-cols-2 md:grid-cols-5">
                @foreach ([
                    ['Hadir terdata', $p['attendees']],
                    ['Sudah memilih', $p['voted']],
                    ['Belum memilih', $p['not_voted']],
                    ['Partisipasi', $p['percent'].'%'],
                    ['PIN terkunci', $p['locked']],
                ] as [$label, $value])
                    <x-filament::section>
                        <p class="text-sm text-gray-500">{{ $label }}</p>
                        <p class="text-3xl font-black tabular-nums">{{ $value }}</p>
                    </x-filament::section>
                @endforeach
            </div>

            @if ($d['headcount'] !== null)
                @php($mismatch = (int) $d['headcount'] !== $p['attendees'])
                <div class="rounded-xl p-4 {{ $mismatch ? 'bg-danger-50 text-danger-800 dark:bg-danger-950 dark:text-danger-200' : 'bg-success-50 text-success-800 dark:bg-success-950 dark:text-success-200' }}" role="status">
                    Rekonsiliasi: hitung kepala <strong>{{ $d['headcount'] }}</strong> · terdata <strong>{{ $p['attendees'] }}</strong> · sudah memilih <strong>{{ $p['voted'] }}</strong>
                    {{ $mismatch ? '⚠ Ada selisih, periksa dan catat di berita acara.' : '✓ Cocok.' }}
                </div>
            @endif

            <div class="flex flex-wrap gap-2">
                @if ($this->headcountAction->isVisible()) {{ $this->headcountAction }} @endif
                <x-filament::button tag="a" :href="route('screens.qr', $election->public_id)" target="_blank" color="gray" icon="heroicon-o-qr-code">
                    Layar QR (proyektor)
                </x-filament::button>
                <x-filament::button tag="a" :href="$d['voterUrl']" target="_blank" color="gray" icon="heroicon-o-device-phone-mobile">
                    Buka halaman pemilih
                </x-filament::button>
            </div>
        </div>

        {{-- Peserta: tab belum/sudah/semua, cari nama, PIN baru (belum), Pulihkan (sudah), unduh Excel. --}}
        @php($tabCounts = $this->participantTabCounts())
        <div class="space-y-3">
            <x-filament::tabs label="Peserta">
                @foreach (['belum' => 'Belum memilih', 'sudah' => 'Sudah memilih', 'semua' => 'Semua'] as $tabKey => $tabLabel)
                    <x-filament::tabs.item
                        :active="$participantTab === $tabKey"
                        :badge="$tabCounts[$tabKey]"
                        :badge-color="$tabKey === 'sudah' ? 'success' : ($tabKey === 'belum' ? 'warning' : 'gray')"
                        wire:click="setParticipantTab('{{ $tabKey }}')"
                    >
                        {{ $tabLabel }}
                    </x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>

            {{ $this->table }}
        </div>

    @endif
</x-filament-panels::page>
