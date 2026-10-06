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
use App\Models\Election;
use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Beranda: pemilihan yang relevan untuk pengguna ini beserta langkah berikutnya, dan panduan
 * singkat Mode Dadakan / Mode Resmi. Langkah dan tombol hanya tampil bila halamannya boleh dibuka.
 */
class HomeGuide extends Widget
{
    protected string $view = 'filament.widgets.home-guide';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * @return Collection<int, Election>
     */
    public function elections(): Collection
    {
        $user = $this->user();

        return Election::query()
            ->whereNotIn('status', [ElectionStatus::Cancelled, ElectionStatus::Archived])
            ->when(! $user->isSuperAdmin(), fn (Builder $query) => $query->where(fn (Builder $access) => $access
                ->whereHas('staff', fn (Builder $staff) => $staff->where('user_id', $user->id))
                ->when($user->isAdminRt(), fn (Builder $resmi) => $resmi->orWhere('mode', ElectionMode::Resmi))))
            ->latest()
            ->limit(6)
            ->get();
    }

    /**
     * Langkah berikutnya untuk satu pemilihan: petunjuk + tombol ke halaman pertama yang boleh dibuka.
     *
     * @return array{hint: string, label: ?string, url: ?string, newTab: bool}
     */
    public function nextStep(Election $election): array
    {
        $dadakan = $election->mode === ElectionMode::Dadakan;
        $query = ['pemilihan' => $election->public_id];
        $edit = fn (): ?array => ElectionResource::canAccess() ? ['Buka pengaturan pemilihan', ElectionResource::getUrl('edit', ['record' => $election])] : null;
        $page = fn (string $page, string $label): ?array => $page::canAccess() ? [$label, $page::getUrl($query)] : null;

        [$hint, $options] = match ($election->status) {
            ElectionStatus::Draft => ['Lengkapi surat suara dan calon, lalu tekan Tandai Siap.', [$edit]],
            ElectionStatus::Ready => $dadakan
                ? ['Petugas pintu sudah bisa mendata yang hadir. Saat acara dimulai, tekan Mulai Pemilihan.', [$edit, fn () => $page(DoorDesk::class, 'Buka Meja Pintu')]]
                : ['Pasang laptop meja dan bilik dengan token di menu Perangkat, lalu tekan Mulai Pemilihan.', [fn () => $page(DevicesPage::class, 'Buka Perangkat'), $edit]],
            ElectionStatus::Berlangsung, ElectionStatus::Paused => $dadakan
                ? ['Pemilihan sedang berjalan. Buka/tutup voting dari Ruang Kendali.', [fn () => $page(ControlRoom::class, 'Buka Ruang Kendali'), fn () => $page(DoorDesk::class, 'Buka Meja Pintu')]]
                : ['Pemilihan sedang berjalan. Petugas meja mengizinkan pemilih dari Meja Izin.', [fn () => $page(DeskPage::class, 'Buka Meja Izin'), fn () => $page(ParticipationPage::class, 'Lihat Partisipasi')]],
            ElectionStatus::Ditutup => ['Pemilihan sudah ditutup. Tampilkan hasil, lalu lanjut ke Verifikasi & Publikasi.', [fn () => $page(ResultScreen::class, 'Buka Layar Hasil'), fn () => $page(VerificationDesk::class, 'Buka Verifikasi')]],
            ElectionStatus::Verifikasi, ElectionStatus::Unpublished => ['Tetapkan yang terpilih/lolos, sahkan berita acara, lalu Publish.', [fn () => $page(VerificationDesk::class, 'Buka Verifikasi'), fn () => $page(ResultScreen::class, 'Buka Layar Hasil')]],
            ElectionStatus::Published => ['Hasil sudah diumumkan di halaman publik.', [fn (): array => ['Lihat di halaman publik', route('public.show', $election->public_id), true]]],
            default => ['', []],
        };

        foreach ($options as $option) {
            $target = $option();

            if ($target !== null) {
                return ['hint' => $hint, 'label' => $target[0], 'url' => $target[1], 'newTab' => $target[2] ?? false];
            }
        }

        return ['hint' => $hint, 'label' => null, 'url' => null, 'newTab' => false];
    }

    /**
     * Panduan per mode. Langkah yang halamannya tidak boleh dibuka pengguna ini disembunyikan.
     *
     * @return array<int, array{title: string, description: string, steps: array<int, array{label: string, detail: string, url: string}>}>
     */
    public function guides(): array
    {
        $user = $this->user();
        $guides = [
            [
                'visible' => $user->isSuperAdmin() || $user->electionAssignments()->exists(),
                'title' => 'Mode Dadakan',
                'description' => 'Untuk rapat/penjaringan: yang hadir didata di pintu, memilih lewat HP dengan nama + PIN.',
                'steps' => [
                    [ElectionResource::class, 'Buat pemilihan Mode Dadakan', 'Tambah surat suara, mis. "Calon Ketua RW".'],
                    [CandidateResource::class, 'Masukkan calon dan fotonya', 'Foto otomatis dipotong kotak dan dikecilkan.'],
                    [ElectionResource::class, 'Tugaskan Panitia dan Petugas Pintu', 'Buka pemilihan, tab Penugasan. Lalu Tandai Siap dan Mulai.'],
                    [DoorDesk::class, 'Meja Pintu: daftarkan yang hadir', 'Ketik nama, tekan Daftarkan & buat PIN, tulis PIN di kertas.'],
                    [ControlRoom::class, 'Ruang Kendali: buka voting', 'Tampilkan Layar QR, tekan BUKA VOTING. Gelombang bantuan untuk lansia.'],
                    [AttendanceList::class, 'Daftar Hadir: pantau siapa yang belum memilih', 'Bisa dicari, disaring, dan diunduh Excel.'],
                    [ResultScreen::class, 'Tutup, lalu Tampilkan Hasil', 'Ranking calon tampil di layar/proyektor.'],
                    [VerificationDesk::class, 'Tetapkan yang lolos, lalu Publish', 'Nama yang lolos tampil di halaman publik.'],
                ],
            ],
            [
                'visible' => $user->isSuperAdmin() || $user->isAdminRt(),
                'title' => 'Mode Resmi',
                'description' => 'Untuk pemilihan Ketua RT + Ketua RW di TPS: meja izin per RT dan laptop bilik.',
                'steps' => [
                    [ElectionResource::class, 'Buat pemilihan Mode Resmi', 'Surat suara Ketua RT (per RT, maks. 5 calon) dan Ketua RW (semua RT).'],
                    [CandidateResource::class, 'Masukkan calon RT tiap RT dan calon RW', ''],
                    [UserResource::class, 'Buat akun Admin RT untuk tiap RT', 'Centang izin Kelola data pemilih dan/atau Petugas Meja.'],
                    [VoterResource::class, 'Isi data pemilih per RT', 'Satu per satu atau import Excel (template tersedia).'],
                    [DevicesPage::class, 'Tandai Siap, lalu pasang laptop dengan token', 'Laptop meja dan bilik tiap RT. Setelah itu Mulai Pemilihan.'],
                    [DeskPage::class, 'Meja Izin: izinkan pemilih ke bilik', 'Cari nama di RT sendiri, tekan Izinkan Memilih.'],
                    [ParticipationPage::class, 'Pantau partisipasi per RT', ''],
                    [VerificationDesk::class, 'Tutup, tetapkan terpilih, berita acara, Publish', ''],
                ],
            ],
        ];

        return collect($guides)
            ->filter(fn (array $guide): bool => $guide['visible'])
            ->map(fn (array $guide): array => [
                'title' => $guide['title'],
                'description' => $guide['description'],
                'steps' => collect($guide['steps'])
                    ->filter(fn (array $step): bool => $step[0]::canAccess())
                    ->map(fn (array $step): array => ['label' => $step[1], 'detail' => $step[2], 'url' => $step[0]::getUrl()])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $guide): bool => $guide['steps'] !== [])
            ->values()
            ->all();
    }
}
