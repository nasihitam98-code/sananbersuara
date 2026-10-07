<?php

namespace App\Filament\Resources\Elections\RelationManagers;

use App\Enums\ElectionStatus;
use App\Models\AuditLog;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Riwayat pemilihan (hanya-baca): kejadian dari Audit Log untuk pemilihan ini, termasuk
 * pemilihan yang sudah dibatalkan. Hak lihat mengikuti AuditLogPolicy (Super Admin).
 */
class HistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    protected static ?string $title = 'Riwayat';

    /**
     * Keterangan yang mudah dibaca untuk kode kejadian; kode lain tampil apa adanya.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'election.created' => 'Pemilihan dibuat',
        'election.updated' => 'Pengaturan diubah',
        'election.status_changed' => 'Status berubah',
        'election.staff_assigned' => 'Panitia/petugas ditugaskan',
        'election.staff_removed' => 'Penugasan dicabut',
        'election.headcount_entered' => 'Hitung kepala diisi',
        'ballot.created' => 'Surat suara ditambah',
        'ballot.updated' => 'Surat suara diubah',
        'ballot.deleted' => 'Surat suara dihapus',
        'candidate.created' => 'Calon ditambah',
        'candidate.updated' => 'Calon diubah',
        'candidate.deleted' => 'Calon dihapus',
        'candidate.photo_replaced' => 'Foto calon diganti',
        'candidate.photo_removed' => 'Foto calon dihapus',
        'gallery.photo_assigned' => 'Foto galeri dipasang',
        'attendee.registered' => 'Peserta didata di pintu',
        'attendee.duplicate_name_confirmed' => 'Nama kembar dikonfirmasi',
        'attendee.pin_failed' => 'PIN salah',
        'attendee.pin_locked' => 'PIN terkunci',
        'attendee.voting_right_restored' => 'Hak pilih dipulihkan',
        'wave.opened' => 'Voting dibuka',
        'wave.extended' => 'Voting diperpanjang',
        'wave.closed' => 'Voting ditutup',
        'round.opened' => 'Putaran baru dibuka',
        'results.revealed' => 'Hasil ditampilkan',
        'result.outcome_decided' => 'Penetapan hasil',
        'report.draft_created' => 'Berita acara dibuat',
        'report.ratified' => 'Berita acara disahkan',
        'report.invalidated' => 'Berita acara perlu dibuat ulang',
        'attendance.exported' => 'Daftar hadir diunduh',
        'recap.exported' => 'Rekap diunduh',
        'tps.paused' => 'TPS dijeda',
        'tps.resumed' => 'TPS dilanjutkan',
        'permit.granted' => 'Izin memilih diberikan',
        'permit.cancelled' => 'Izin memilih dibatalkan',
        'device.paired' => 'Perangkat dipasang',
        'device.released' => 'Perangkat dilepas',
        'correction.requested' => 'Koreksi diajukan',
        'correction.approved' => 'Koreksi disetujui',
        'correction.rejected' => 'Koreksi ditolak',
        'vote_detail.opened' => 'Detail suara dibuka',
        'retention.linkage_purged' => 'Keterkaitan pemilih-suara dihapus (retensi)',
        'retention.personal_data_purged' => 'Data pribadi dihapus (retensi)',
    ];

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('occurred_at')->label('Waktu')->dateTime('d M Y H:i:s'),
                TextColumn::make('actor_label')->label('Pelaku')->placeholder(fn (AuditLog $record): string => $record->actor_type === 'system' ? 'Sistem' : '-'),
                TextColumn::make('action')
                    ->label('Kejadian')
                    ->formatStateUsing(fn (AuditLog $record): string => static::describe($record))
                    ->searchable(),
                TextColumn::make('note')->label('Catatan')->placeholder('-')->wrap(),
            ]);
    }

    public static function describe(AuditLog $record): string
    {
        $label = self::LABELS[$record->action] ?? $record->action;

        if ($record->action === 'election.status_changed') {
            $status = fn (?string $value): string => ElectionStatus::tryFrom((string) $value)?->getLabel() ?? '-';
            $label .= ': '.$status($record->meta['from'] ?? null).' → '.$status($record->meta['to'] ?? null);
        }

        if (str_starts_with($record->action, 'wave.') && isset($record->meta['number'])) {
            $label .= ': '.(filled($record->meta['name'] ?? null) ? $record->meta['name'] : 'Gelombang '.$record->meta['number']);
        }

        return $label;
    }
}
