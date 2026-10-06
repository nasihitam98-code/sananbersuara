<x-filament-panels::page>
    @php($election = $this->election())
    @php($desk = $this->desk())

    @if ($election === null)
        <x-filament::section>Belum ada pemilihan Mode Resmi yang Siap atau Berlangsung.</x-filament::section>
    @else
        <div wire:poll.3s class="space-y-6">
            {{-- Status laptop --}}
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::badge size="lg" :color="$election->status->getColor()">{{ $election->name }} · {{ $election->status->getLabel() }}</x-filament::badge>
                @if ($desk)
                    <x-filament::badge size="lg" color="success" icon="heroicon-o-check-circle">Laptop Meja {{ $desk->unit->name }} terdaftar</x-filament::badge>
                @else
                    <x-filament::badge size="lg" color="danger" icon="heroicon-o-x-circle">Bukan laptop Meja RT Anda: tombol aksi nonaktif</x-filament::badge>
                    <a href="{{ route('desk.pair') }}" class="text-sm text-primary-600 underline">Pasang laptop ini sebagai Meja (butuh token dari Super Admin)</a>
                @endif
                @if ($pause = $this->tpsPause())
                    <x-filament::badge size="lg" color="warning" icon="heroicon-o-pause">TPS DIJEDA: {{ $pause->reason_code->getLabel() }} sejak {{ $pause->paused_at->format('H:i') }}</x-filament::badge>
                @endif
                {{ $this->pauseTpsAction }}
                {{ $this->resumeTpsAction }}
            </div>

            @if ($lastAssignment)
                <div class="rounded-2xl bg-success-600 p-6 text-center text-white" role="status">
                    <p class="text-lg">{{ $lastAssignment['name'] }}</p>
                    <p class="text-4xl font-black">Silakan ke {{ $lastAssignment['booth'] }}</p>
                    <x-filament::button color="gray" class="mt-3" wire:click="dismissAssignment">OK</x-filament::button>
                </div>
            @endif

            @if ($issuedToken)
                <div class="rounded-2xl border-2 border-primary-500 p-6 text-center" role="status">
                    <p>Token untuk <strong>{{ $issuedToken['booth'] }}</strong> (berlaku {{ $election->setting('booth_token_minutes') }} menit, sekali pakai):</p>
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
                        <p class="text-sm">{{ $row['percent'] }}% sudah memilih</p>
                    </x-filament::section>
                @endforeach
            </div>

            {{-- Bilik --}}
            <x-filament::section heading="Bilik">
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
                            @endif
                            @if ($booth->activePermit)
                                <p class="mt-1 text-sm">Pemilih di dalam: {{ $booth->activePermit->voter->name }}</p>
                            @endif
                            @if ($desk)
                                <div class="mt-3 flex flex-wrap gap-2">
                                    {{ ($this->issueBoothTokenAction)(['booth' => $booth->public_id]) }}
                                    @if ($booth->isPaired())
                                        {{ ($this->releaseBoothAction)(['booth' => $booth->public_id]) }}
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            {{-- Cari pemilih --}}
            <x-filament::section heading="Cari pemilih {{ $this->user()->unit?->name }}">
                <x-filament::input.wrapper>
                    <x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Ketik minimal 2 huruf nama" class="text-lg" autofocus />
                </x-filament::input.wrapper>

                <ul class="mt-4 divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($this->results() as $row)
                        @php($voter = $row['voter'])
                        @php($status = $row['status'])
                        @php($ballots = $election->ballots)
                        @php($hasPending = in_array('BELUM', $status['ballots'], true))
                        @php($eligibleAny = collect($status['ballots'])->contains(fn ($value) => $value !== 'TIDAK_BERHAK'))
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div>
                                <p class="text-lg font-bold">{{ $voter->name }}</p>
                                <p class="text-sm text-gray-500">{{ $voter->address }} · {{ $voter->voter_number }}</p>
                                <p class="mt-1 flex flex-wrap gap-2 text-sm">
                                    @foreach ($ballots as $ballot)
                                        @php($value = $status['ballots'][$ballot->id] ?? 'TIDAK_BERHAK')
                                        <x-filament::badge :color="match ($value) { 'SUDAH' => 'success', 'BELUM' => 'warning', default => 'gray' }">
                                            {{ $ballot->title }}: {{ match ($value) { 'SUDAH' => '✓ Sudah', 'BELUM' => '○ Belum', default => '— Tidak berhak' } }}
                                        </x-filament::badge>
                                    @endforeach
                                    @if (! $voter->is_active)
                                        <x-filament::badge color="gray">Nonaktif</x-filament::badge>
                                    @endif
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                @if ($status['permit'])
                                    <x-filament::badge color="info" size="lg">Di {{ $status['permit']->device->name() }}</x-filament::badge>
                                    @if ($desk)
                                        {{ ($this->cancelPermitAction)(['permit' => $status['permit']->public_id]) }}
                                    @endif
                                @elseif (! $eligibleAny)
                                    <x-filament::badge color="gray" size="lg">Tidak berhak di pemilihan ini</x-filament::badge>
                                @elseif (! $hasPending)
                                    <x-filament::badge color="success" size="lg">✓ Selesai memilih</x-filament::badge>
                                @elseif ($desk && $election->status === \App\Enums\ElectionStatus::Berlangsung)
                                    {{ ($this->grantAction)(['voter' => $voter->public_id]) }}
                                @else
                                    <x-filament::badge color="gray">Izinkan tidak tersedia</x-filament::badge>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
