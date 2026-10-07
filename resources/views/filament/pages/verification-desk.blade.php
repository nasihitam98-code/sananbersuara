<x-filament-panels::page>
    @php($election = $this->election())

    @include('filament.pages.partials.election-picker', ['election' => $election, 'elections' => $this->availableElections()])

    @if ($election === null)
        <x-filament::section>
            Belum ada pemilihan yang sudah ditutup.
        </x-filament::section>
    @else
        @if (\App\Filament\Pages\ResultScreen::canAccess())
            <div>
                <x-filament::link icon="heroicon-m-arrow-left" :href="\App\Filament\Pages\ResultScreen::getUrl(['pemilihan' => $election->public_id])">
                    Lihat Layar Hasil (proyektor)
                </x-filament::link>
            </div>
        @endif
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
            </div>

                {{-- Langkah berurutan: apa yang sudah selesai dan apa yang dikerjakan sekarang. --}}
                @php($verified = in_array($election->status, [\App\Enums\ElectionStatus::Verifikasi, \App\Enums\ElectionStatus::Published, \App\Enums\ElectionStatus::Unpublished], true))
                @php($slotRows = $this->slotRows())
                @php($decided = collect($slotRows)->filter(fn ($row) => $row['outcome'] !== null)->count())
                @php($reportRows = $this->reportRows())
                @php($ratified = collect($reportRows)->filter(fn ($row) => $row['current']?->status === \App\Enums\ReportStatus::Disahkan)->count())
                @php($published = $election->status === \App\Enums\ElectionStatus::Published)
                @php($steps = [
                    ['Mulai verifikasi', $verified, 'Mengunci hasil untuk diperiksa panitia.'],
                    ['Tetapkan yang lolos/terpilih', $verified && $decided === count($slotRows), "{$decided} dari ".count($slotRows).' surat suara sudah ditetapkan (bagian ② di bawah).'],
                    ['Berita acara: buat draf, cetak, tanda tangan, sahkan', $verified && $ratified === count($reportRows), "{$ratified} dari ".count($reportRows).' berita acara disahkan (bagian ③ di bawah).'],
                    ['Publikasikan ke Halaman Publik', $published, 'Hasil resmi tampil di Halaman Publik (tanpa angka suara).'],
                ])
                @php($current = collect($steps)->search(fn ($step) => ! $step[1]))

                <ol class="mt-5 space-y-3">
                    @foreach ($steps as $index => [$label, $done, $detail])
                        <li @class([
                            'flex flex-wrap items-center justify-between gap-3 rounded-xl p-3',
                            'bg-primary-50 ring-1 ring-primary-200 dark:bg-primary-500/10 dark:ring-primary-500/30' => $index === $current,
                        ])>
                            <div class="flex items-center gap-3">
                                <span @class([
                                    'grid h-8 w-8 shrink-0 place-items-center rounded-full text-sm font-bold',
                                    'bg-success-600 text-white' => $done,
                                    'bg-primary-600 text-white' => ! $done && $index === $current,
                                    'bg-gray-200 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! $done && $index !== $current,
                                ])>{{ $done ? '✓' : $index + 1 }}</span>
                                <div>
                                    <p class="font-semibold">{{ $label }}</p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $detail }}</p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if ($index === 0 && $this->startVerificationAction->isVisible()) {{ $this->startVerificationAction }} @endif
                                @if ($index === 3)
                                    @if ($this->publishAction->isVisible()) {{ $this->publishAction }} @endif
                                    @if ($this->unpublishAction->isVisible()) {{ $this->unpublishAction }} @endif
                                    @if ($this->reopenVerificationAction->isVisible()) {{ $this->reopenVerificationAction }} @endif
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>

                @if ($this->nextRoundAction->isVisible())
                    <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4 text-sm text-gray-500 dark:border-white/10">
                        <span>Ada calon <strong>seri</strong> di posisi penentu? Lakukan sebelum langkah 2:</span>
                        {{ $this->nextRoundAction }}
                    </div>
                @endif


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
        <x-filament::section id="penetapan" heading="② Penetapan hasil oleh panitia" description="Klik Tetapkan di setiap surat suara. Sistem hanya menampilkan angka; yang terpilih/lolos ditetapkan panitia.">
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
        <x-filament::section id="berita-acara" heading="③ Berita acara" description="Urutan: Buat draf → Lihat / Cetak (Simpan sebagai PDF) → tanda tangani panitia → Sahkan. Bila penetapan diubah, berita acara dibuat ulang.">
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
