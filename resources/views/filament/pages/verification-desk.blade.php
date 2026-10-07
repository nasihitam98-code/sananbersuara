<x-filament-panels::page>
    @php($election = $this->election())

    @include('filament.pages.partials.election-picker', ['election' => $election, 'elections' => $this->availableElections()])

    @if ($election === null)
        <x-filament::section>
            Belum ada pemilihan yang sudah ditutup.
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-sm text-gray-500">Status</p>
                    <x-filament::badge size="lg" :color="$election->status->getColor()">{{ $election->status->getLabel() }}</x-filament::badge>
                    @if ($election->status === \App\Enums\ElectionStatus::Published)
                        <p class="mt-2 text-sm">Halaman publik: <a class="text-primary-600 underline" href="{{ route('public.show', $election->public_id) }}" target="_blank">{{ route('public.show', $election->public_id) }}</a></p>
                    @endif
                    @if ($election->vote_links_destroyed_at)
                        <p class="mt-2 text-sm text-gray-500">Keterkaitan pemilih-pilihan sudah dihapus permanen pada {{ $election->vote_links_destroyed_at->format('d-m-Y') }} (K26).</p>
                    @elseif ($deadline = $this->retentionDeadline())
                        <p class="mt-2 text-sm text-gray-500">Detail suara (siapa memilih siapa) akan dihapus otomatis pada <strong>{{ $deadline->format('d-m-Y') }}</strong>. @if ($this->extendRetentionAction->isVisible()) {{ $this->extendRetentionAction }} @endif</p>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($this->startVerificationAction->isVisible()) {{ $this->startVerificationAction }} @endif
                    @if ($this->publishAction->isVisible()) {{ $this->publishAction }} @endif
                    @if ($this->unpublishAction->isVisible()) {{ $this->unpublishAction }} @endif
                    @if ($this->reopenVerificationAction->isVisible()) {{ $this->reopenVerificationAction }} @endif
                    @if ($this->nextRoundAction->isVisible()) {{ $this->nextRoundAction }} @endif
                </div>
            </div>

            @if (in_array($election->status, [\App\Enums\ElectionStatus::Verifikasi, \App\Enums\ElectionStatus::Unpublished], true))
                @php($problems = $this->publishProblems())
                <div class="mt-4 rounded-xl p-4 {{ $problems ? 'bg-warning-50 text-warning-800 dark:bg-warning-950 dark:text-warning-200' : 'bg-success-50 text-success-800 dark:bg-success-950 dark:text-success-200' }}">
                    @if ($problems)
                        <p class="font-semibold">Sebelum bisa dipublikasikan:</p>
                        <ul class="list-disc ps-5">
                            @foreach ($problems as $problem)
                                <li>{{ $problem }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p class="font-semibold">✓ Semua penetapan diisi dan berita acara disahkan. Siap dipublikasikan.</p>
                    @endif
                </div>
            @endif
        </x-filament::section>

        {{-- 1. Penetapan --}}
        <x-filament::section heading="1. Penetapan hasil oleh panitia" description="Sistem hanya menampilkan angka. Yang terpilih/lolos ditetapkan di sini.">
            <div class="space-y-6">
                @foreach ($this->slotRows() as $row)
                    @php($tally = $row['tally'])
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-lg font-bold">{{ $row['title'] }} @if ($row['round'] > 1)<span class="text-sm font-normal text-warning-600">· Putaran {{ $row['round'] }}</span>@endif</p>
                                <p class="text-sm text-gray-500">
                                    Suara sah {{ $tally['valid'] }} · Dibatalkan {{ $tally['cancelled'] }}
                                    @if ($tally['tie_at_top'])
                                        · <strong class="text-warning-600">SERI di peringkat teratas</strong>
                                    @endif
                                </p>
                            </div>
                            @if (($this->decideAction)(['slot' => $row['key']])->isVisible()) {{ ($this->decideAction)(['slot' => $row['key']]) }} @endif
                        </div>

                        <table class="mt-3 w-full text-sm">
                            <thead class="text-left text-gray-500">
                                <tr><th class="py-1">#</th><th>Calon</th><th class="text-right">Suara</th><th class="text-right">%</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($tally['candidates'] as $line)
                                    <tr class="border-t border-gray-100 dark:border-white/5">
                                        <td class="py-1">{{ $line['rank'] }}</td>
                                        <td>{{ $line['candidate']->displayNumber() }} · {{ $line['candidate']->nameWithOrigin() }}</td>
                                        <td class="text-right tabular-nums">{{ $line['votes'] }}</td>
                                        <td class="text-right tabular-nums">{{ $line['percent'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        <div class="mt-3 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                            @if ($row['outcome'])
                                <strong>{{ $row['outcome']->label() }}:</strong>
                                {{ $row['outcome']->candidates->map(fn ($c) => $c->displayNumber().' · '.$c->name)->implode(', ') ?: '-' }}
                                @if ($row['outcome']->note)
                                    <span class="text-gray-500">({{ $row['outcome']->note }})</span>
                                @endif
                            @else
                                <span class="text-warning-600">Belum ada penetapan.</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- 2. Berita acara --}}
        <x-filament::section heading="2. Berita acara" description="Buat draf → cetak (Simpan sebagai PDF) → tanda tangani → Sahkan. Penetapan yang berubah membuat berita acara lingkup itu harus dibuat ulang.">
            <div class="space-y-4">
                @foreach ($this->reportRows() as $row)
                    @php($current = $row['current'])
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="font-bold">Berita acara {{ $row['label'] }}</p>
                                @if ($current)
                                    <p class="text-sm">
                                        {{ $current->number }} ·
                                        <x-filament::badge :color="$current->status->getColor()" size="sm">{{ $current->status->getLabel() }}</x-filament::badge>
                                    </p>
                                @else
                                    <p class="text-sm text-gray-500">Belum ada draf.</p>
                                @endif
                            </div>
                            @if (in_array($election->status, [\App\Enums\ElectionStatus::Ditutup, \App\Enums\ElectionStatus::Verifikasi, \App\Enums\ElectionStatus::Unpublished], true))
                                <div class="flex flex-wrap gap-2">
                                    @if ($current)
                                        <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-printer" :href="route('reports.show', $current->public_id)" target="_blank">Lihat / Cetak</x-filament::button>
                                    @endif
                                    @if ($current?->status === \App\Enums\ReportStatus::Draft)
                                        @if (($this->ratifyReportAction)(['report' => $current->public_id])->isVisible()) {{ ($this->ratifyReportAction)(['report' => $current->public_id]) }} @endif
                                    @endif
                                    @if (($this->draftReportAction)(['unit' => $row['scope']?->id ?? 0, 'revise' => $current?->status === \App\Enums\ReportStatus::Disahkan])->isVisible()) {{ ($this->draftReportAction)(['unit' => $row['scope']?->id ?? 0, 'revise' => $current?->status === \App\Enums\ReportStatus::Disahkan]) }} @endif
                                </div>
                            @elseif ($current)
                                <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-printer" :href="route('reports.show', $current->public_id)" target="_blank">Lihat / Cetak</x-filament::button>
                            @endif
                        </div>

                        @if ($row['history']->count() > 1)
                            <details class="mt-2 text-sm text-gray-500">
                                <summary>Riwayat versi ({{ $row['history']->count() }})</summary>
                                <ul class="mt-1 list-disc ps-5">
                                    @foreach ($row['history'] as $version)
                                        <li>
                                            <a class="underline" href="{{ route('reports.show', $version->public_id) }}" target="_blank">{{ $version->number }}</a>
                                            · {{ $version->status->getLabel() }}
                                            @if ($version->revision_reason) · alasan: {{ $version->revision_reason }} @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
