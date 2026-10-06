<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="space-y-1 text-sm">
                <p>Backup otomatis: tiap <strong>{{ config('voting.backup.idle_interval_minutes') / 60 }} jam</strong>, dan tiap <strong>{{ config('voting.backup.live_interval_minutes') }} menit</strong> saat ada pemilihan berlangsung. Disimpan {{ config('voting.backup.keep_days') }} hari.</p>
                <p>Isi: database + foto calon, zip terenkripsi AES-256.</p>
                @if ($this->offsiteConfigured())
                    <p class="text-success-600">✓ Salinan off-server aktif (disk: {{ config('voting.backup.offsite_disk') }}).</p>
                @else
                    <p class="text-warning-600">⚠ Salinan off-server belum dikonfigurasi (BACKUP_OFFSITE_DISK). Unduh backup secara berkala dan simpan di luar server.</p>
                @endif
            </div>
            {{ $this->backupNowAction }}
        </div>
    </x-filament::section>

    <x-filament::section heading="Riwayat backup">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr><th class="py-1 pe-3">Waktu</th><th class="pe-3">Jenis</th><th class="pe-3">Status</th><th class="pe-3">Ukuran</th><th class="pe-3">Off-server</th><th class="pe-3">Pembuat</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($this->backups() as $backup)
                        <tr class="border-t border-gray-100 align-top dark:border-white/5">
                            <td class="py-2 pe-3">{{ $backup->created_at->format('d/m/Y H:i') }}</td>
                            <td class="pe-3">{{ $backup->trigger }}</td>
                            <td class="pe-3">
                                <x-filament::badge :color="match ($backup->status) { 'BERHASIL' => 'success', 'GAGAL' => 'danger', default => 'warning' }">{{ $backup->status }}</x-filament::badge>
                                @if ($backup->error)<br><span class="text-xs text-danger-600">{{ \Illuminate\Support\Str::limit($backup->error, 120) }}</span>@endif
                            </td>
                            <td class="pe-3">{{ $backup->size_bytes ? $backup->humanSize() : '-' }}</td>
                            <td class="pe-3">{{ $backup->offsite_copied ? '✓' : '—' }}</td>
                            <td class="pe-3">{{ $backup->creator?->name ?? 'Sistem' }}</td>
                            <td class="flex gap-2 py-2">
                                @if ($backup->status === 'BERHASIL')
                                    {{ ($this->downloadAction)(['backup' => $backup->public_id]) }}
                                    {{ ($this->restoreAction)(['backup' => $backup->public_id]) }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-2 text-gray-500">Belum ada backup.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
