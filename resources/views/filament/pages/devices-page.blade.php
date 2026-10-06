<x-filament-panels::page>
    @php($election = $this->election())

    @if ($election === null)
        <x-filament::section>Perangkat dikelola untuk pemilihan Mode Resmi yang Siap atau Berlangsung.</x-filament::section>
    @else
        <div wire:poll.5s class="space-y-6">
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::badge size="lg" :color="$election->status->getColor()">{{ $election->name }} · {{ $election->status->getLabel() }}</x-filament::badge>
                @php($disconnected = $this->disconnectedCount())
                @if ($disconnected > 0)
                    <x-filament::badge size="lg" color="danger" icon="heroicon-o-exclamation-triangle">{{ $disconnected }} perangkat terputus lebih dari 30 detik</x-filament::badge>
                @endif
                <span class="text-sm text-gray-500">Laptop meja dipasang di <strong>{{ route('desk.pair') }}</strong>; laptop bilik di <strong>{{ route('booth.show') }}</strong>.</span>
            </div>

            @if ($issuedToken)
                <div class="rounded-2xl border-2 border-primary-500 p-6 text-center" role="status">
                    <p>Token untuk <strong>{{ $issuedToken['desk'] }}</strong> (tampil sekali, berlaku {{ $election->setting('desk_token_hours') }} jam):</p>
                    <p class="font-mono text-5xl font-black tracking-widest text-primary-700 dark:text-primary-300">{{ $issuedToken['token'] }}</p>
                    <x-filament::button color="gray" class="mt-3" wire:click="dismissToken">Tutup</x-filament::button>
                </div>
            @endif

            @foreach ($this->devicesByUnit() as $unitName => $devices)
                <x-filament::section :heading="$unitName">
                    @php($unitId = $devices->first()->unit_id)
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        @if ($pause = $this->pauseFor($unitName))
                            <x-filament::badge color="warning" icon="heroicon-o-pause">TPS DIJEDA: {{ $pause->reason_code->getLabel() }} sejak {{ $pause->paused_at->format('H:i') }}</x-filament::badge>
                            {{ ($this->resumeTpsAction)(['unit' => $unitId]) }}
                        @elseif ($election->status === \App\Enums\ElectionStatus::Berlangsung)
                            {{ ($this->pauseTpsAction)(['unit' => $unitId]) }}
                        @endif
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-gray-500">
                                <tr><th class="py-1 pe-3">Slot</th><th class="pe-3">Status</th><th class="pe-3">Label laptop</th><th class="pe-3">Terakhir aktif</th><th class="pe-3">Tersambung</th><th class="pe-3">Browser</th><th class="pe-3">IP</th><th></th></tr>
                            </thead>
                            <tbody>
                                @foreach ($devices as $device)
                                    @php($state = $device->connectionState())
                                    @php($color = match ($state) { 'Menunggu' => 'success', 'Dipesan', 'Sedang dipakai' => 'info', 'Terputus' => 'danger', default => 'gray' })
                                    <tr class="border-t border-gray-100 dark:border-white/5">
                                        <td class="py-2 pe-3 font-mono">{{ $device->code() }}</td>
                                        <td class="pe-3"><x-filament::badge :color="$color">{{ $state }}</x-filament::badge></td>
                                        <td class="pe-3">{{ $device->label ?? '-' }}</td>
                                        <td class="pe-3">{{ $device->last_seen_at?->diffForHumans() ?? '-' }}</td>
                                        <td class="pe-3">{{ $device->paired_at?->format('d/m H:i') ?? '-' }}</td>
                                        <td class="pe-3 max-w-[16rem] truncate" title="{{ $device->user_agent }}">{{ \Illuminate\Support\Str::limit((string) $device->user_agent, 40) ?: '-' }}</td>
                                        <td class="pe-3">{{ $device->ip_address ?? '-' }}</td>
                                        <td class="flex gap-2 py-2">
                                            @if ($device->kind === \App\Enums\DeviceKind::Meja)
                                                {{ ($this->issueDeskTokenAction)(['device' => $device->public_id]) }}
                                            @endif
                                            @if ($device->isPaired())
                                                {{ ($this->releaseDeviceAction)(['device' => $device->public_id]) }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
