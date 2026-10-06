<x-filament-panels::page>
    @php($election = $this->election())

    @if ($election === null)
        <x-filament::section>Koreksi suara tersedia untuk pemilihan Mode Resmi yang berlangsung atau belum dipublikasikan.</x-filament::section>
    @else
        <x-filament::section heading="Ajukan pembatalan suara" description="Cari pemilih yang sudah memilih. Pilihannya tidak ditampilkan.">
            <x-filament::input.wrapper>
                <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Ketik minimal 2 huruf nama" />
            </x-filament::input.wrapper>

            <ul class="mt-3 divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($this->candidatesForCorrection() as $row)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                        <div>
                            <p class="font-semibold">{{ $row['voter']->name }}</p>
                            <p class="text-sm text-gray-500">{{ $row['voter']->unit->name }} · {{ $row['voter']->address }} · {{ $row['voter']->voter_number }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($row['ballots'] as $ballot)
                                <span class="text-sm">{{ $ballot->title }}:</span>
                                {{ ($this->requestAction)(['voter' => $row['voter']->public_id, 'ballot' => $ballot->public_id]) }}
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>

        <x-filament::section heading="Daftar pengajuan">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500">
                        <tr><th class="py-1 pe-3">Waktu</th><th class="pe-3">Pemilih</th><th class="pe-3">Surat suara</th><th class="pe-3">Alasan</th><th class="pe-3">Pengaju</th><th class="pe-3">Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse ($this->corrections() as $correction)
                            <tr class="border-t border-gray-100 align-top dark:border-white/5">
                                <td class="py-2 pe-3">{{ $correction->created_at->format('d/m H:i') }}</td>
                                <td class="pe-3">{{ $correction->voter->name }}<br><span class="text-gray-500">{{ $correction->unit->name }} · {{ $correction->voter->voter_number }}</span></td>
                                <td class="pe-3">{{ $correction->ballot->title }}</td>
                                <td class="pe-3">{{ $correction->reason_code->getLabel() }}@if ($correction->note)<br><span class="text-gray-500">{{ $correction->note }}</span>@endif</td>
                                <td class="pe-3">{{ $correction->requester->name }}</td>
                                <td class="pe-3">
                                    <x-filament::badge :color="$correction->status->getColor()">{{ $correction->status->getLabel() }}</x-filament::badge>
                                    @if ($correction->decision_note)<br><span class="text-gray-500">{{ $correction->decision_note }}</span>@endif
                                </td>
                                <td class="flex flex-wrap gap-2 py-2">
                                    @if ($this->isPending($correction))
                                        {{ ($this->approveAction)(['correction' => $correction->public_id]) }}
                                        {{ ($this->rejectAction)(['correction' => $correction->public_id]) }}
                                        @if ($correction->requested_by === auth()->id())
                                            {{ ($this->withdrawAction)(['correction' => $correction->public_id]) }}
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-2 text-gray-500">Belum ada pengajuan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
