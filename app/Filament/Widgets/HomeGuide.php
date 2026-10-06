<?php

namespace App\Filament\Widgets;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Filament\Pages\AttendanceList;
use App\Filament\Pages\ControlRoom;
use App\Filament\Pages\DeskPage;
use App\Filament\Pages\DevicesPage;
use App\Filament\Pages\DoorDesk;
use App\Filament\Pages\ParticipationPage;
use App\Filament\Pages\ResultScreen;
use App\Filament\Pages\VerificationDesk;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Filament\Resources\Elections\ElectionResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Voters\VoterResource;
use App\Filament\Support\Workspace;
use App\Models\Election;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Beranda: pilih mode dulu (Super Admin), lalu tampilan per mode berisi tahapan pemilihan,
 * tombol yang perlu ditekan sekarang, dan panduan langkah. Langkah dan tombol hanya tampil
 * bila halamannya boleh dibuka pengguna ini.
 */
class HomeGuide extends Widget
{
    protected string $view = 'filament.widgets.home-guide';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /**
     * Urutan tahap pemilihan untuk penanda kemajuan.
     *
     * @var array<int, string>
     */
    public const STAGES = ['Persiapan', 'Siap', 'Berlangsung', 'Ditutup', 'Verifikasi', 'Diumumkan'];

    public function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function mode(): ?ElectionMode
    {
        return Workspace::current();
    }

    /**
     * Pilihan mode untuk layar "Pilih mode".
     *
     * @return array<int, array{mode: ElectionMode, title: string, description: string, examples: string, url: string}>
     */
    public function modeChoices(): array
    {
        $descriptions = [
            ElectionMode::Dadakan->value => ['Rapat / penjaringan', 'Yang hadir didata di pintu, lalu memilih lewat HP dengan nama + PIN.', 'Contoh: penjaringan calon Ketua RW 10 Oktober.'],
            ElectionMode::Resmi->value => ['Pemilihan RT + RW di TPS', 'Pemilih terdaftar per RT, diizinkan di meja, memilih di laptop bilik.', 'Contoh: hari H pemilihan Ketua RT dan Ketua RW.'],
        ];

        return collect(Workspace::allowedModes())
            ->map(fn (ElectionMode $mode): array => [
                'mode' => $mode,
                'title' => 'Mode '.$mode->getLabel(),
                'description' => $descriptions[$mode->value][0].'. '.$descriptions[$mode->value][1],
                'examples' => $descriptions[$mode->value][2],
                'url' => route('workspace.switch', strtolower($mode->value)),
            ])
            ->all();
    }

    /**
     * Pemilihan pada mode kerja ini yang boleh dilihat pengguna, terbaru dulu.
     *
     * @return Collection<int, Election>
     */
    public function elections(): Collection
    {
        $user = $this->user();
        $mode = $this->mode();

        if ($mode === null) {
            return collect();
        }

        return Election::query()
            ->where('mode', $mode)
            ->whereNotIn('status', [ElectionStatus::Cancelled, ElectionStatus::Archived])
            ->when(! $user->isSuperAdmin() && $mode === ElectionMode::Dadakan, fn (Builder $query) => $query
                ->whereHas('staff', fn (Builder $staff) => $staff->where('user_id', $user->id)))
            ->latest()
            ->limit(6)
            ->get();
    }

    /**
     * Pemilihan utama: yang sedang berlangsung, atau yang terbaru.
     */
    public function primaryElection(): ?Election
    {
        $elections = $this->elections();

        return $elections->first(fn (Election $election): bool => $election->status->isLive()) ?? $elections->first();
    }

    public function stageIndex(Election $election): int
    {
        return match ($election->status) {
            ElectionStatus::Draft => 0,
            ElectionStatus::Ready => 1,
            ElectionStatus::Berlangsung, ElectionStatus::Paused => 2,
            ElectionStatus::Ditutup => 3,
            ElectionStatus::Verifikasi, ElectionStatus::Unpublished => 4,
            default => 5,
        };
    }

    /**
     * Petunjuk tahap sekarang + tombol ke halaman yang boleh dibuka (tombol pertama = utama).
     *
     * @return array{hint: string, buttons: array<int, array{label: string, url: string, newTab: bool}>}
     */
    public function nextStep(Election $election): array
    {
        $dadakan = $election->mode === ElectionMode::Dadakan;
        $query = ['pemilihan' => $election->public_id];
        $edit = fn (string $label): ?array => ElectionResource::canAccess() ? [$label, ElectionResource::getUrl('edit', ['record' => $election])] : null;
        $page = fn (string $page, string $label): ?array => $page::canAccess() ? [$label, $page::getUrl($query)] : null;

        [$hint, $options] = match ($election->status) {
            ElectionStatus::Draft => ['Isi surat suara dan calon. Setelah lengkap, buka pengaturan lalu tekan Status → Tandai Siap.', [
                fn () => $edit('Buka pengaturan pemilihan'),
                fn () => CandidateResource::canAccess() ? ['Isi calon', CandidateResource::getUrl()] : null,
            ]],
            ElectionStatus::Ready => $dadakan
                ? ['Sudah siap. Petugas pintu sudah bisa mendata yang datang. Saat acara dimulai: Status → Mulai Pemilihan.', [
                    fn () => $edit('Buka pengaturan (Mulai Pemilihan)'),
                    fn () => $page(DoorDesk::class, 'Buka Meja Pintu'),
                ]]
                : ['Sudah siap. Pasang laptop meja dan bilik dengan token, lalu Status → Mulai Pemilihan.', [
                    fn () => $page(DevicesPage::class, 'Pasang laptop (Perangkat)'),
                    fn () => $edit('Buka pengaturan (Mulai Pemilihan)'),
                ]],
            ElectionStatus::Berlangsung, ElectionStatus::Paused => $dadakan
                ? ['Pemilihan berjalan. Buka/tutup voting dan tampilkan QR dari Ruang Kendali.', [
                    fn () => $page(ControlRoom::class, 'Buka Ruang Kendali'),
                    fn () => $page(DoorDesk::class, 'Buka Meja Pintu'),
                    fn () => $page(AttendanceList::class, 'Daftar Hadir'),
                ]]
                : ['Pemilihan berjalan. Petugas meja mengizinkan pemilih ke bilik.', [
                    fn () => $page(DeskPage::class, 'Buka Meja Izin'),
                    fn () => $page(ParticipationPage::class, 'Lihat Partisipasi'),
                ]],
            ElectionStatus::Ditutup => ['Pemilihan sudah ditutup. Tampilkan hasil, lalu lanjut ke Verifikasi & Publikasi.', [
                fn () => $page(ResultScreen::class, 'Buka Layar Hasil'),
                fn () => $page(VerificationDesk::class, 'Buka Verifikasi'),
            ]],
            ElectionStatus::Verifikasi, ElectionStatus::Unpublished => ['Tetapkan yang terpilih/lolos, sahkan berita acara, lalu Publish.', [
                fn () => $page(VerificationDesk::class, 'Buka Verifikasi'),
                fn () => $page(ResultScreen::class, 'Buka Layar Hasil'),
            ]],
            ElectionStatus::Published => ['Hasil sudah diumumkan di halaman publik.', [
                fn (): array => ['Lihat di halaman publik', route('public.show', $election->public_id), true],
            ]],
            default => ['', []],
        };

        $buttons = collect($options)
            ->map(fn (callable $option): ?array => $option())
            ->filter()
            ->map(fn (array $target): array => ['label' => $target[0], 'url' => $target[1], 'newTab' => $target[2] ?? false])
            ->values()
            ->all();

        return ['hint' => $hint, 'buttons' => $buttons];
    }

    /**
     * Panduan langkah untuk mode kerja ini. Langkah yang halamannya tidak boleh dibuka disembunyikan.
     *
     * @return array<int, array{label: string, detail: string, url: string}>
     */
    public function guideSteps(): array
    {
        $steps = match ($this->mode()) {
            ElectionMode::Dadakan => [
                [ElectionResource::class, 'Buat pemilihan', 'Tambah surat suara, mis. "Calon Ketua RW", berhak: semua yang hadir.'],
                [CandidateResource::class, 'Masukkan calon dan fotonya', 'Foto otomatis dipotong kotak dan dikecilkan.'],
                [ElectionResource::class, 'Tugaskan Panitia dan Petugas Pintu', 'Di pemilihan, tab Panitia & Petugas Pintu. Lalu Status → Tandai Siap → Mulai.'],
                [DoorDesk::class, 'Meja Pintu: daftarkan yang hadir', 'Ketik nama, tekan Daftarkan & buat PIN, tulis PIN di kertas.'],
                [ControlRoom::class, 'Ruang Kendali: buka voting', 'Tampilkan Layar QR, tekan BUKA VOTING. Gelombang bantuan untuk lansia.'],
                [AttendanceList::class, 'Daftar Hadir: pantau siapa yang belum memilih', 'Bisa dicari, disaring, dan diunduh Excel.'],
                [ResultScreen::class, 'Tutup, lalu Tampilkan Hasil', 'Ranking calon tampil di layar/proyektor.'],
                [VerificationDesk::class, 'Tetapkan yang lolos, lalu Publish', 'Nama yang lolos tampil di halaman publik.'],
            ],
            ElectionMode::Resmi => [
                [ElectionResource::class, 'Buat pemilihan', 'Surat suara Ketua RT (per RT, maks. 5 calon) dan Ketua RW (semua RT).'],
                [CandidateResource::class, 'Masukkan calon RT tiap RT dan calon RW', ''],
                [UserResource::class, 'Buat akun Admin RT untuk tiap RT', 'Centang izin Kelola data pemilih dan/atau Petugas Meja.'],
                [VoterResource::class, 'Isi data pemilih per RT', 'Satu per satu atau import Excel (template tersedia).'],
                [DevicesPage::class, 'Tandai Siap, lalu pasang laptop dengan token', 'Laptop meja dan bilik tiap RT. Setelah itu Mulai Pemilihan.'],
                [DeskPage::class, 'Meja Izin: izinkan pemilih ke bilik', 'Cari nama di RT sendiri, tekan Izinkan Memilih.'],
                [ParticipationPage::class, 'Pantau partisipasi per RT', ''],
                [VerificationDesk::class, 'Tutup, tetapkan terpilih, berita acara, Publish', ''],
            ],
            default => [],
        };

        return collect($steps)
            ->filter(fn (array $step): bool => $step[0]::canAccess())
            ->map(fn (array $step): array => ['label' => $step[1], 'detail' => $step[2], 'url' => $step[0]::getUrl()])
            ->values()
            ->all();
    }
}
