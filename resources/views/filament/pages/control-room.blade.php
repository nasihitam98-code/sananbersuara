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
                            <p class="text-sm text-gray-500">Gelombang {{ $d['wave']->number }} · {{ $d['wave']->kind->getLabel() }}</p>
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
                        @endif
                    </div>
                    <div class="flex flex-col gap-2">
                        {{ $this->openWaveAction }}
                        {{ $this->extendWaveAction }}
                        {{ $this->closeWaveAction }}
                        @if ($election->status === \App\Enums\ElectionStatus::Ready)
                            <p class="text-sm text-warning-600">Pemilihan belum dimulai. Super Admin menekan "Mulai Pemilihan" di menu Pemilihan.</p>
                        @endif
                    </div>
                </div>
            </x-filament::section>

            {{-- Partisipasi (tanpa angka per kandidat) --}}
            @php($p = $d['participation'])
            <div class="grid gap-4 grid-cols-2 md:grid-cols-6">
                @foreach ([
                    ['Hadir terdata', $p['attendees']],
                    ['Sudah memilih', $p['voted']],
                    ['Belum memilih', $p['not_voted']],
                    ['Partisipasi', $p['percent'].'%'],
                    ['PIN terkunci', $p['locked']],
                    ['Dibantu (HP panitia)', $p['assisted']],
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
                {{ $this->headcountAction }}
                <x-filament::button tag="a" :href="route('screens.qr', $election->public_id)" target="_blank" color="gray" icon="heroicon-o-qr-code">
                    Layar QR (proyektor)
                </x-filament::button>
                <x-filament::button tag="a" :href="$d['voterUrl']" target="_blank" color="gray" icon="heroicon-o-device-phone-mobile">
                    Buka halaman pemilih
                </x-filament::button>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Cari peserta: pulihkan hak pilih --}}
            <x-filament::section heading="Cari peserta (Pulihkan Hak Pilih)">
                <x-filament::input.wrapper>
                    <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Ketik minimal 2 huruf nama" />
                </x-filament::input.wrapper>

                <ul class="mt-3 divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($this->searchResults() as $row)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <div>
                                <p class="font-semibold">{{ $row['attendee']->name }}</p>
                                <p class="text-sm text-gray-500">No. {{ $row['attendee']->displayNumber() }}
                                    · {{ $row['voted'] ? '✓ Sudah memilih' : '○ Belum memilih' }}
                                    {{ $row['attendee']->isPinLocked() ? '· 🔒 PIN terkunci' : '' }}
                                </p>
                            </div>
                            @if ($election->status->isLive())
                                {{ ($this->restoreAction)(['attendee' => $row['attendee']->public_id]) }}
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>

            {{-- PIN terkunci --}}
            <x-filament::section heading="PIN terkunci ({{ $this->lockedAttendees()->count() }})">
                <ul class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($this->lockedAttendees() as $attendee)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span>🔒 {{ $attendee->name }} <span class="text-sm text-gray-500">No. {{ $attendee->displayNumber() }}</span></span>
                            @if ($election->status->isLive())
                                {{ ($this->restoreAction)(['attendee' => $attendee->public_id]) }}
                            @endif
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">Tidak ada.</li>
                    @endforelse
                </ul>
            </x-filament::section>
        </div>

        {{-- Belum memilih --}}
        <x-filament::section heading="Belum memilih (untuk dipanggil di gelombang bantuan)" collapsible>
            <x-filament::input.wrapper>
                <x-filament::input type="search" wire:model.live.debounce.400ms="notVotedFilter" placeholder="Saring nama" />
            </x-filament::input.wrapper>
            <p class="mt-2 text-sm text-gray-500">
                Menampilkan maksimal 200 nama.
                <x-filament::link :href="\App\Filament\Pages\AttendanceList::getUrl(['pemilihan' => $election->public_id])">Lihat semua di Daftar Hadir</x-filament::link>
            </p>
            <ul class="mt-3 grid gap-x-6 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($this->notVoted() as $attendee)
                    <li class="py-1">{{ $attendee->name }} <span class="text-sm text-gray-500">· No. {{ $attendee->displayNumber() }}</span></li>
                @empty
                    <li class="py-1 text-gray-500">Semua yang hadir sudah memilih.</li>
                @endforelse
            </ul>
        </x-filament::section>

    @endif
</x-filament-panels::page>
