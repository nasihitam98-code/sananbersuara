<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Entri audit append-only. Ditulis hanya lewat AuditLogger; perubahan dan penghapusan
 * ditolak di aplikasi dan di database (trigger).
 */
#[WithoutTimestamps]
class AuditLog extends Model
{
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Audit log tidak bisa diubah.'));
        static::deleting(fn (): never => throw new LogicException('Audit log tidak bisa dihapus.'));
    }

    /**
     * Padanan bahasa Indonesia untuk kode aksi (kode aslinya tetap tersimpan dan tampil sebagai tooltip).
     *
     * @var array<string, string>
     */
    public const ACTION_LABELS = [
        'access.denied' => 'Akses ditolak',
        'attendance.exported' => 'Daftar hadir diunduh',
        'attendee.duplicate_name_confirmed' => 'Nama kembar dikonfirmasi',
        'attendee.pin_failed' => 'PIN salah',
        'attendee.pin_locked' => 'PIN terkunci',
        'attendee.registered' => 'Warga didata (Meja Pintu)',
        'attendee.voting_right_restored' => 'Hak pilih dipulihkan',
        'auth.failed' => 'Login gagal',
        'auth.lockout' => 'Login dikunci sementara',
        'auth.login' => 'Login',
        'auth.logout' => 'Logout',
        'backup.created' => 'Backup dibuat',
        'backup.downloaded' => 'Backup diunduh',
        'backup.failed' => 'Backup gagal',
        'backup.restore_started' => 'Restore dimulai',
        'backup.restored' => 'Data dipulihkan dari backup',
        'ballot.created' => 'Surat suara dibuat',
        'ballot.deleted' => 'Surat suara dihapus',
        'ballot.updated' => 'Surat suara diubah',
        'candidate.created' => 'Calon ditambahkan',
        'candidate.deleted' => 'Calon dihapus',
        'candidate.photo_removed' => 'Foto calon dihapus',
        'candidate.photo_replaced' => 'Foto calon diganti',
        'candidate.updated' => 'Calon diubah',
        'correction.approved' => 'Koreksi disetujui',
        'correction.rejected' => 'Koreksi ditolak',
        'correction.requested' => 'Koreksi diajukan',
        'correction.withdrawn' => 'Koreksi ditarik',
        'device.paired' => 'Laptop dipasang',
        'device.released' => 'Laptop dilepas',
        'device.token_issued' => 'Token laptop dibuat',
        'device.token_rejected' => 'Token laptop ditolak',
        'election.created' => 'Pemilihan dibuat',
        'election.deleted' => 'Pemilihan dihapus',
        'election.headcount_entered' => 'Hitung kepala diisi',
        'election.public_listing' => 'Tampil di halaman publik diubah',
        'election.staff_assigned' => 'Panitia ditugaskan',
        'election.staff_removed' => 'Penugasan panitia dicabut',
        'election.status_changed' => 'Status pemilihan berubah',
        'election.updated' => 'Pengaturan pemilihan diubah',
        'gallery.photo_added' => 'Gambar masuk Media',
        'gallery.photo_assigned' => 'Gambar Media dipasang ke calon',
        'gallery.photo_deleted' => 'Gambar dihapus dari Media',
        'permit.cancelled' => 'Izin memilih dibatalkan',
        'permit.granted' => 'Izin memilih diberikan',
        'recap.exported' => 'Rekap diunduh',
        'report.draft_created' => 'Draf berita acara dibuat',
        'report.invalidated' => 'Berita acara dibatalkan',
        'report.ratified' => 'Berita acara disahkan',
        'result.outcome_decided' => 'Hasil ditetapkan',
        'results.revealed' => 'Hasil ditampilkan (Layar Hasil)',
        'retention.linkage_extended' => 'Masa simpan data sengketa diperpanjang',
        'retention.linkage_purged' => 'Data sengketa dihapus otomatis',
        'retention.personal_data_purged' => 'Data pribadi dihapus otomatis',
        'round.opened' => 'Putaran baru dibuka',
        'settings.display_updated' => 'Teks tampilan diubah',
        'settings.logo_removed' => 'Logo dihapus',
        'settings.logo_replaced' => 'Logo diganti',
        'tps.paused' => 'TPS dijeda',
        'tps.resumed' => 'TPS dilanjutkan',
        'unit.created' => 'RT ditambahkan',
        'unit.deleted' => 'RT dihapus',
        'unit.updated' => 'RT diubah',
        'user.created' => 'Akun dibuat',
        'user.password_changed' => 'Password diganti',
        'user.password_reset_by_admin' => 'Password direset admin',
        'user.password_reset_by_console' => 'Password direset dari server',
        'user.two_factor_reset_by_admin' => '2FA direset admin',
        'user.updated' => 'Akun diubah',
        'vote_detail.opened' => 'Detail Suara dibuka',
        'vote_detail.viewed' => 'Detail Suara dilihat',
        'voter.deactivated' => 'Pemilih dinonaktifkan',
        'voter.deleted' => 'Pemilih dihapus',
        'voter.duplicate_rejected' => 'Pemilih ganda ditolak',
        'voter.imported' => 'Pemilih diimpor',
        'voter.reactivated' => 'Pemilih diaktifkan lagi',
        'voter.unit_corrected' => 'RT pemilih dikoreksi',
        'voter.updated' => 'Pemilih diubah',
        'wave.closed' => 'Voting ditutup',
        'wave.extended' => 'Voting diperpanjang',
        'wave.opened' => 'Voting dibuka',
    ];

    /**
     * Nama objek (kelas model) dalam bahasa Indonesia.
     *
     * @var array<string, string>
     */
    public const SUBJECT_LABELS = [
        'Attendee' => 'Peserta hadir',
        'Backup' => 'Backup',
        'Ballot' => 'Surat suara',
        'Candidate' => 'Calon',
        'Correction' => 'Koreksi',
        'Device' => 'Laptop',
        'Election' => 'Pemilihan',
        'ElectionStaff' => 'Penugasan',
        'GalleryPhoto' => 'Gambar Media',
        'OfficialReport' => 'Berita acara',
        'Outcome' => 'Hasil',
        'Permit' => 'Izin memilih',
        'Round' => 'Putaran',
        'Unit' => 'RT',
        'User' => 'Akun',
        'Voter' => 'Pemilih',
        'Wave' => 'Sesi voting',
    ];

    public static function actionLabel(?string $action): string
    {
        return self::ACTION_LABELS[$action] ?? (string) $action;
    }

    public static function subjectLabel(?string $subjectType): ?string
    {
        if ($subjectType === null) {
            return null;
        }

        $short = class_basename($subjectType);

        return self::SUBJECT_LABELS[$short] ?? $short;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
