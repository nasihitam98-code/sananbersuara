<x-filament-panels::page>
    @php($election = $this->election())
    @php($desk = $this->desk())

    @if ($election === null)
        <x-filament::section>Belum ada pemilihan Mode Resmi yang Siap atau Berlangsung.</x-filament::section>
    @else
        <div wire:poll.5s data-auto-refresh="off" class="space-y-6">
            {{-- Status laptop --}}
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::badge size="lg" :color="$election->status->getColor()">{{ $election->name }} · {{ $election->status->getLabel() }}</x-filament::badge>
                @if ($desk)
                    <x-filament::badge size="lg" color="success" icon="heroicon-o-check-circle">Laptop Meja {{ $desk->unit->name }} terdaftar</x-filament::badge>
                @else
                    <x-filament::badge size="lg" color="danger" icon="heroicon-o-x-circle">Bukan laptop Meja RT Anda: tombol aksi nonaktif</x-filament::badge>
                    @if ($this->pairOwnDeskAction->isVisible()) {{ $this->pairOwnDeskAction }} @endif
                    <a href="{{ route('desk.pair') }}" class="text-sm text-gray-500 underline">atau pakai token Meja dari Super Admin</a>
                @endif
                @if ($pause = $this->tpsPause())
                    <x-filament::badge size="lg" color="warning" icon="heroicon-o-pause">TPS DIJEDA: {{ $pause->reason_code->getLabel() }} sejak {{ $pause->paused_at->format('H:i') }}</x-filament::badge>
                @endif
                @if ($this->pauseTpsAction->isVisible()) {{ $this->pauseTpsAction }} @endif
                @if ($this->resumeTpsAction->isVisible()) {{ $this->resumeTpsAction }} @endif
            </div>

            @if ($lastAssignment && $this->assignmentStillActive())
                <div class="rounded-2xl bg-success-600 p-6 text-center text-white" role="status">
                    <p class="text-lg">{{ $lastAssignment['name'] }}</p>
                    <p class="text-4xl font-black">Silakan ke {{ $lastAssignment['booth'] }}</p>
                    <x-filament::button color="gray" class="mt-3" wire:click="dismissAssignment">OK</x-filament::button>
                </div>
            @endif

            @if ($issuedToken)
                <div class="rounded-2xl border-2 border-primary-500 p-6 text-center" role="status">
                    <p>Token untuk <strong>{{ $issuedToken['booth'] }}</strong> (tetap berlaku sampai bilik dilepas; bisa dipakai lagi untuk menyambung ulang):</p>
                    <p class="font-mono text-5xl font-black tracking-widest text-primary-700 dark:text-primary-300">{{ $issuedToken['token'] }}</p>
                    <p class="text-sm text-gray-500">Di laptop bilik buka <strong>{{ url('/bilik') }}</strong> lalu ketik token ini.</p>
                    <x-filament::button color="gray" class="mt-3" wire:click="dismissToken">Tutup</x-filament::button>
                </div>
            @endif

            {{-- Partisipasi RT --}}
            <div class="grid gap-4 md:grid-cols-3">
                @foreach ($this->participation() as $row)
                    <x-filament::section>
                        <p class="text-sm text-gray-500">{{ $row['title'] }}</p>
                        <p class="text-3xl font-black tabular-nums">{{ $row['voted'] }} <span class="text-base font-normal text-gray-500">dari {{ $row['eligible'] }}</span></p>
                        <p class="text-sm">{{ \Illuminate\Support\Number::percentage($row['percent'], maxPrecision: 1) }} sudah memilih</p>
                    </x-filament::section>
                @endforeach
            </div>

            {{-- Bilik --}}
            <x-filament::section heading="Bilik">
                @if ($desk && $this->addBoothAction->isVisible())
                    <x-slot name="afterHeader">{{ $this->addBoothAction }}</x-slot>
                @endif
                {{-- Alamat laptop bilik selalu terlihat (tidak perlu dicari), dengan tombol salin. --}}
                <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-primary-50 p-3 ring-1 ring-primary-200 dark:bg-primary-500/10 dark:ring-primary-500/30" x-data="{ copied: false }">
                    <span class="text-sm text-gray-600 dark:text-gray-300">Di <strong>laptop bilik</strong> (bukan laptop ini) buka:</span>
                    <span class="font-mono text-lg font-bold text-primary-700 dark:text-primary-300">{{ preg_replace('#^https?://#', '', route('booth.show')) }}</span>
                    <button type="button" class="desk-btn-gray" x-on:click="navigator.clipboard?.writeText(@js(route('booth.show'))); copied = true; setTimeout(() => copied = false, 2000)">
                        <span x-show="! copied">Salin link</span><span x-show="copied" x-cloak>✓ Tersalin</span>
                    </button>
                    <span class="text-sm text-gray-500">lalu ketik token dari tombol <strong>Buat Token</strong> di bawah.</span>
                </div>
                <div class="grid gap-3 md:grid-cols-3">
                    @foreach ($this->booths() as $booth)
                        @php($state = $booth->connectionState())
                        @php($color = match ($state) { 'Menunggu' => 'success', 'Dipesan', 'Sedang dipakai' => 'info', 'Terputus' => 'danger', default => 'gray' })
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                            <div class="flex items-center justify-between">
                                <p class="text-lg font-bold">{{ $booth->name() }}</p>
                                <x-filament::badge :color="$color">{{ $state }}</x-filament::badge>
                            </div>
                            <p class="text-sm text-gray-500">{{ $booth->label ?? 'Belum ada laptop' }}</p>
                            @if ($booth->last_seen_at)
                                <p class="text-xs text-gray-500">Terakhir aktif {{ $booth->last_seen_at->diffForHumans() }}</p>
                                @if ($state === 'Terputus')
                                    <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">Nyalakan laptop bilik ini dan buka lagi /bilik: tersambung sendiri, tanpa token. Lepas hanya bila laptopnya diganti.</p>
                                @endif
                            @endif
                            @if ($booth->activePermit)
                                <p class="mt-1 text-sm">Pemilih di dalam: {{ $booth->activePermit->voter->name }}</p>
                            @endif
                            {{-- Token bilik tetap terlihat dan berlaku sampai bilik dilepas: laptop yang lupa (Incognito/data terhapus) cukup diketik token yang sama. --}}
                            @if ($desk && ($pendingToken = $booth->pendingToken()))
                                <p class="mt-2 rounded-lg bg-primary-50 px-3 py-2 dark:bg-primary-500/10">
                                    <span class="text-xs text-gray-500">Token bilik ini (bisa dipakai lagi):</span>
                                    <span class="block font-mono text-2xl font-black tracking-widest text-primary-700 dark:text-primary-300">{{ $pendingToken }}</span>
                                    <span class="text-xs text-gray-500">berlaku sampai bilik dilepas</span>
                                </p>
                            @endif
                            @if ($desk)
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @if (($this->issueBoothTokenAction)(['booth' => $booth->public_id])->isVisible()) {{ ($this->issueBoothTokenAction)(['booth' => $booth->public_id]) }} @endif
                                    @if ($booth->isPaired())
                                        @if (($this->releaseBoothAction)(['booth' => $booth->public_id])->isVisible()) {{ ($this->releaseBoothAction)(['booth' => $booth->public_id]) }} @endif
                                    @elseif (($this->removeBoothAction)(['booth' => $booth->public_id])->isVisible())
                                        {{ ($this->removeBoothAction)(['booth' => $booth->public_id]) }}
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            {{-- Daftar pemilih RT: langsung tampil, tab Belum/Sudah/Semua dengan jumlah; kotak cari hanya menyaring. --}}
            @php($counts = $this->voterCounts())
            @php($rows = $this->results())
            @php($started = $election->status !== \App\Enums\ElectionStatus::Ready)
            <x-filament::section heading="Pemilih {{ $this->user()->unit?->name }}">
                <x-filament::tabs class="mb-3">
                    <x-filament::tabs.item :active="$voterTab === 'belum'" wire:click="$set('voterTab', 'belum')">Belum memilih ({{ $counts['belum'] }})</x-filament::tabs.item>
                    <x-filament::tabs.item :active="$voterTab === 'sudah'" wire:click="$set('voterTab', 'sudah')">Sudah memilih ({{ $counts['sudah'] }})</x-filament::tabs.item>
                    <x-filament::tabs.item :active="$voterTab === 'semua'" wire:click="$set('voterTab', 'semua')">Semua ({{ $counts['semua'] }})</x-filament::tabs.item>
                </x-filament::tabs>

                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::input.wrapper class="min-w-[12rem] grow">
                        <x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Saring nama atau No. absen …" class="text-lg" />
                    </x-filament::input.wrapper>
                    @if ($this->emergencyAddAction->isVisible()) {{ $this->emergencyAddAction }} @endif
                </div>

                @if (! $started)
                    <p class="mt-3 text-sm text-gray-500">Pemilihan belum dimulai. Tombol <strong>Izinkan Memilih</strong> muncul setelah Super Admin menekan Mulai.</p>
                @endif

                @if ($rows === [])
                    <p class="mt-4 text-sm text-gray-500">{{ filled(trim($this->search)) ? 'Tidak ada nama yang cocok.' : match ($voterTab) { 'belum' => 'Semua pemilih sudah memilih.', 'sudah' => 'Belum ada yang memilih.', default => 'Belum ada pemilih di RT ini.' } }}</p>
                @endif

                @php($can = [
                    'grant' => $desk && $election->status === \App\Enums\ElectionStatus::Berlangsung && $this->grantAction->isVisible(),
                    'cancel' => $desk && $this->cancelPermitAction->isVisible(),
                    'edit' => $this->editNameAction->isVisible(),
                    'strike' => $this->strikeAction->isVisible(),
                    'restore' => $this->restoreStruckAction->isVisible(),
                    'correct' => $this->requestCorrectionAction->isVisible(),
                ])
                @php($btn = ['grant' => 'desk-btn-grant', 'danger' => 'desk-btn-danger', 'gray' => 'desk-btn-gray', 'link' => 'desk-btn-link'])
                @php($pill = ['success' => 'desk-pill desk-pill-success', 'warning' => 'desk-pill desk-pill-warning', 'info' => 'desk-pill desk-pill-info', 'gray' => 'desk-pill desk-pill-gray'])

                <ul class="mt-4 divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($rows as $row)
                        @php($voter = $row['voter'])
                        @php($status = $row['status'])
                        {{-- Nomor berjalan per tab (1, 2, 3, ...): nomor terakhir = jumlah di tab itu, mis. yang belum memilih. --}}
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div class="flex items-start gap-3">
                                <span class="w-10 flex-none pt-0.5 text-right text-lg font-bold tabular-nums text-gray-400">{{ $loop->iteration }}</span>
                                <div>
                                <p class="text-lg font-bold">{{ $voter->name }}</p>
                                <p class="text-sm text-gray-500">{{ collect([$voter->rt_number ? 'No. absen '.$voter->rt_number : null, $voter->address])->filter()->implode(' · ') }}</p>
                                @if ($started)
                                    <p class="mt-1 flex flex-wrap gap-2 text-sm">
                                        @foreach ($election->ballots as $ballot)
                                            @php($value = $status['ballots'][$ballot->id] ?? 'TIDAK_BERHAK')
                                            <span class="{{ $pill[match ($value) { 'SUDAH' => 'success', 'BELUM' => 'warning', default => 'gray' }] }}">{{ $ballot->title }}: {{ match ($value) { 'SUDAH' => '✓ Sudah', 'BELUM' => '○ Belum', default => '— Tidak berhak' } }}</span>
                                        @endforeach
                                    </p>
                                @endif
                                @if (! $voter->is_active)
                                    <span class="{{ $pill['gray'] }} mt-1 inline-block">Nonaktif</span>
                                @endif
                                </div>
                            </div>
                            {{-- Tombol baris dibuat ringan (tombol biasa yang membuka aksi Filament): daftar ini dikirim ulang tiap
                                 3 detik, dan komponen aksi lengkap per baris membuat satu pembaruan ±580 KB. Izin dicek sekali di atas. --}}
                            @php($id = \Illuminate\Support\Js::from(['voter' => $voter->public_id]))
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @switch ($row['state'])
                                    @case ('di_bilik')
                                        <span class="{{ $pill['info'] }} text-sm">Di {{ $status['permit']->device->name() }}</span>
                                        @if ($can['cancel'])
                                            <button type="button" class="{{ $btn['danger'] }}" wire:click="mountAction('cancelPermit', {{ \Illuminate\Support\Js::from(['permit' => $status['permit']->public_id]) }})">Batalkan Izin</button>
                                        @endif
                                        @break
                                    @case ('sudah')
                                        <span class="{{ $pill['success'] }} text-sm">✓ Selesai memilih</span>
                                        @break
                                    @case ('tidak_berhak')
                                        <span class="{{ $pill['gray'] }} text-sm">{{ $row['can_restore'] ? 'Dihapus dari daftar' : 'Tidak berhak di pemilihan ini' }}</span>
                                        @break
                                    @default
                                        @if ($can['grant'])
                                            <button type="button" class="{{ $btn['grant'] }}" wire:click="mountAction('grant', {{ $id }})">Izinkan Memilih</button>
                                        @else
                                            <span class="{{ $pill['warning'] }}">○ Belum memilih</span>
                                        @endif
                                @endswitch

                                @if ($can['edit'])
                                    <button type="button" class="{{ $btn['link'] }}" wire:click="mountAction('editName', {{ $id }})">✎ Ubah</button>
                                @endif
                                {{-- Nama yang tidak berhak (data utama maupun tambahan darurat): dicoret dari daftar, bisa dikembalikan dari tab Semua. --}}
                                @if ($row['can_strike'] && $can['strike'])
                                    <button type="button" class="{{ $btn['link'] }}" wire:click="mountAction('strike', {{ $id }})">Hapus dari daftar</button>
                                @endif
                                @if ($row['can_restore'] && $can['restore'])
                                    <button type="button" class="{{ $btn['link'] }}" wire:click="mountAction('restoreStruck', {{ $id }})">↩ Kembalikan ke daftar</button>
                                @endif

                                {{-- Koreksi suara: diputuskan langsung di RT; lencana menunggu hanya untuk pengajuan lama/dari luar Meja Izin. --}}
                                @if ($row['correction_pending'])
                                    <x-filament::badge color="warning" icon="heroicon-o-clock">Koreksi menunggu Super Admin</x-filament::badge>
                                @elseif ($can['correct'] && in_array('SUDAH', $status['ballots'], true))
                                    <button type="button" class="{{ $btn['gray'] }}" wire:click="mountAction('requestCorrection', {{ $id }})">Koreksi</button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
