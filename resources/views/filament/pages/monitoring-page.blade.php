<x-filament-panels::page>
    @php($election = $this->election())

    @if ($election === null)
        <x-filament::section>Monitoring tersedia untuk pemilihan Mode Resmi yang Siap atau Berlangsung.</x-filament::section>
    @else
        @php($rows = $this->rows())
        @php($summary = $this->summary($rows))
        <div wire:poll.5s data-auto-refresh="off" class="space-y-6">
            <div class="flex flex-wrap items-center gap-3">
                <x-filament::badge size="lg" :color="$election->status->getColor()">{{ $election->name }} · {{ $election->status->getLabel() }}</x-filament::badge>
                @if ($summary['problems'] > 0)
                    <x-filament::badge size="lg" color="danger" icon="heroicon-o-exclamation-triangle">{{ $summary['problems'] }} RT perlu dicek</x-filament::badge>
                @else
                    <x-filament::badge size="lg" color="success" icon="heroicon-o-check-circle">Semua RT normal</x-filament::badge>
                @endif
                @if ($summary['paused'] > 0)
                    <x-filament::badge size="lg" color="warning" icon="heroicon-o-pause">{{ $summary['paused'] }} TPS dijeda</x-filament::badge>
                @endif
                <span class="text-sm text-gray-500">Diperbarui tiap 5 detik. Hanya jumlah sudah/belum memilih, tanpa angka per calon.</span>
            </div>

            <x-filament::section>
                <p class="text-4xl font-black tabular-nums">{{ $summary['done'] }} <span class="text-lg font-normal text-gray-500">dari {{ $summary['total'] }} pemilih sudah memilih = {{ \Illuminate\Support\Number::percentage($summary['percent'], maxPrecision: 1) }}</span></p>
                <div class="mt-2 h-4 w-full rounded-full bg-gray-100 dark:bg-white/10"><div class="h-4 rounded-full bg-primary-500" style="width: {{ $summary['percent'] }}%"></div></div>
            </x-filament::section>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($rows as $row)
                    @php($border = match ($row['tone']) { 'danger' => 'border-danger-500', 'warning' => 'border-warning-500', default => 'border-gray-200 dark:border-white/10' })
                    <div class="rounded-xl border-2 {{ $border }} bg-white p-4 dark:bg-gray-900">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-lg font-bold">{{ $row['unit']->name }}</p>
                            @if ($row['problems'] !== [])
                                <x-filament::badge color="danger">Perlu dicek</x-filament::badge>
                            @elseif ($row['pause'])
                                <x-filament::badge color="warning">TPS dijeda</x-filament::badge>
                            @else
                                <x-filament::badge color="success">Normal</x-filament::badge>
                            @endif
                        </div>

                        <p class="mt-2 text-2xl font-black tabular-nums">{{ $row['done'] }}<span class="text-sm font-normal text-gray-500"> / {{ $row['total'] }} sudah memilih · {{ \Illuminate\Support\Number::percentage($row['percent'], maxPrecision: 1) }}</span></p>
                        <div class="mt-1 h-2 w-full rounded-full bg-gray-100 dark:bg-white/10"><div class="h-2 rounded-full bg-primary-500" style="width: {{ $row['percent'] }}%"></div></div>

                        <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                            <dt class="text-gray-500">Laptop meja</dt>
                            <dd>
                                @php($deskColor = match ($row['desk']) { 'Menunggu' => 'success', 'Terputus' => 'danger', default => 'gray' })
                                <x-filament::badge size="sm" :color="$deskColor">{{ $row['desk'] === 'Menunggu' ? 'Tersambung' : $row['desk'] }}</x-filament::badge>
                            </dd>
                            <dt class="text-gray-500">Laptop bilik</dt>
                            <dd>{{ $row['booths']['paired'] }} dari {{ $row['booths']['slots'] }} tersambung</dd>
                            <dt class="text-gray-500">Bilik terisi</dt>
                            <dd>{{ $row['booths']['busy'] }}</dd>
                        </dl>

                        @if ($row['pause'])
                            <p class="mt-2 text-sm font-semibold text-warning-600">TPS dijeda: {{ $row['pause'] }}</p>
                        @endif

                        @foreach ($row['problems'] as $problem)
                            <p class="mt-1 text-sm font-semibold text-danger-600">⚠ {{ $problem }}</p>
                        @endforeach
                    </div>
                @endforeach
            </div>

            <p class="text-sm text-gray-500">
                Tindakan: token &amp; lepas laptop atau jeda TPS di
                <a class="text-primary-600 underline" href="{{ \App\Filament\Pages\DevicesPage::getUrl() }}">Perangkat</a>;
                nama yang belum/sudah memilih di
                <a class="text-primary-600 underline" href="{{ \App\Filament\Pages\ParticipationPage::getUrl() }}">Partisipasi</a>.
            </p>
        </div>
    @endif
</x-filament-panels::page>
