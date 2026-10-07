<?php

namespace App\Filament\Widgets;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Filament\Pages\ControlRoom;
use App\Filament\Pages\DeskPage;
use App\Filament\Pages\DevicesPage;
use App\Filament\Pages\DoorDesk;
use App\Filament\Pages\GalleryPage;
use App\Filament\Pages\ParticipationPage;
use App\Filament\Pages\ResultScreen;
use App\Filament\Pages\VerificationDesk;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Filament\Resources\Elections\ElectionResource;
use App\Filament\Resources\Units\UnitResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Voters\VoterResource;
use App\Filament\Support\Reauthenticate;
use App\Filament\Support\Workspace;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\User;
use App\Models\Voter;
use App\Services\AuditLogger;
use App\Services\CandidatePhotoProcessor;
use App\Services\Voting\ElectionLifecycle;
use App\Services\Voting\VotingException;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Beranda: pilih mode dulu (Super Admin), lalu tampilan per mode berisi tahapan pemilihan,
 * tombol yang perlu ditekan sekarang, dan panduan langkah. Langkah dan tombol hanya tampil
 * bila halamannya boleh dibuka pengguna ini.
 */
class HomeGuide extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

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
        return Workspace::elections($this->user());
    }

    /**
     * Pemilihan yang sedang dikerjakan (dipilih lewat kartu "Masuk"). Null = tampilkan daftar pemilihan.
     */
    public function primaryElection(): ?Election
    {
        return Workspace::election();
    }

    /**
     * Pemilihan lain yang sedang berlangsung, untuk peringatan dan konfirmasi saat berpindah.
     */
    public function otherLiveElection(?Election $except = null): ?Election
    {
        return Workspace::otherLiveElection($except);
    }

    /**
     * Ringkasan singkat untuk kartu pemilihan.
     */
    public function cardSummary(Election $election): string
    {
        $candidates = $election->ballots()->withCount('ballotCandidates')->get()->sum('ballot_candidates_count');

        if ($election->mode === ElectionMode::Dadakan) {
            $attendees = $election->attendees()->count();

            return "{$candidates} calon · {$attendees} hadir terdata";
        }

        return "{$election->ballots()->count()} surat suara · {$candidates} calon";
    }

    /**
     * Hapus pemilihan yang belum pernah dimulai (beserta surat suara, calon, dan foto calonnya).
     */
    public function deleteElectionAction(): Action
    {
        return Action::make('deleteElection')
            ->label('Hapus')
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->link()
            ->visible(fn (array $arguments): bool => ($election = $this->electionFromArguments($arguments)) !== null && $this->user()->can('delete', $election))
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Hapus "'.($this->electionFromArguments($arguments)?->name ?? '').'"?')
            ->modalDescription('Surat suara, calon, foto calon, dan penugasan panitia pemilihan ini ikut terhapus. Tidak bisa dibatalkan.')
            ->modalSubmitActionLabel('Ya, hapus')
            ->action(function (array $arguments): void {
                $election = $this->electionFromArguments($arguments);
                abort_unless($election !== null && $this->user()->can('delete', $election), 403);

                $photos = app(CandidatePhotoProcessor::class);
                Candidate::query()->whereHas('ballot', fn (Builder $ballot) => $ballot->where('election_id', $election->id))
                    ->whereNotNull('photo_key')
                    ->get()
                    ->each(fn (Candidate $candidate) => $photos->remove($candidate));

                $snapshot = $election->only(['name', 'mode']);
                $election->delete();
                app(AuditLogger::class)->log('election.deleted', meta: $snapshot);

                if (Workspace::election()?->is($election)) {
                    Workspace::chooseElection(null);
                }

                Notification::make()->title("Pemilihan \"{$snapshot['name']}\" dihapus.")->success()->send();
            });
    }

    /**
     * Pemilihan yang sudah pernah dimulai tidak bisa dihapus (riwayat wajib tersimpan); Batalkan
     * menyembunyikannya dari daftar. Pemilihan yang sedang berlangsung dibatalkan dari halamannya sendiri.
     */
    public function cancelElectionAction(): Action
    {
        return Action::make('cancelElection')
            ->label('Batalkan')
            ->icon('heroicon-m-x-circle')
            ->color('gray')
            ->link()
            ->visible(fn (array $arguments): bool => ($election = $this->electionFromArguments($arguments)) !== null
                && $this->user()->isSuperAdmin()
                && in_array($election->status, [ElectionStatus::Ditutup, ElectionStatus::Verifikasi], true))
            ->modalHeading(fn (array $arguments): string => 'Batalkan "'.($this->electionFromArguments($arguments)?->name ?? '').'"?')
            ->modalDescription('Pemilihan yang sudah dimulai tidak bisa dihapus. Batalkan menyembunyikannya dari daftar; datanya tetap tersimpan di Riwayat.')
            ->modalSubmitActionLabel('Ya, batalkan')
            ->schema([
                Textarea::make('note')->label('Alasan')->default('Data latihan')->required()->maxLength(500),
                Reauthenticate::field(),
            ])
            ->action(function (array $data, array $arguments): void {
                $election = $this->electionFromArguments($arguments);
                abort_unless($election !== null && $this->user()->isSuperAdmin(), 403);

                try {
                    app(ElectionLifecycle::class)->cancel($election, $this->user(), $data['note']);
                } catch (VotingException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                if (Workspace::election()?->is($election)) {
                    Workspace::chooseElection(null);
                }

                Notification::make()->title("Pemilihan \"{$election->name}\" dibatalkan dan disembunyikan.")->success()->send();
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function electionFromArguments(array $arguments): ?Election
    {
        return $this->elections()->firstWhere('public_id', $arguments['election'] ?? null);
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
     * Tautan per tahap untuk penanda kemajuan. Hanya tahap yang sudah lewat atau sedang
     * berjalan yang bisa diklik, dan hanya bila halamannya boleh dibuka.
     *
     * @return array<int, array{label: string, url: ?string, description: string}>
     */
    public function stages(Election $election): array
    {
        $current = $this->stageIndex($election);
        $dadakan = $election->mode === ElectionMode::Dadakan;
        $query = ['pemilihan' => $election->public_id];
        $edit = ElectionResource::canAccess() ? ElectionResource::getUrl('edit', ['record' => $election]) : null;
        $page = fn (string $page): ?string => $page::canAccess() ? $page::getUrl($query) : null;

        $targets = [
            [$edit, 'Surat suara, calon, dan penugasan diisi.'],
            [$edit ?? ($dadakan ? $page(DoorDesk::class) : $page(DevicesPage::class)), 'Data dikunci (Tandai Siap). Calon masih bisa diubah sampai Mulai.'],
            [$dadakan ? ($page(ControlRoom::class) ?? $page(DoorDesk::class)) : ($page(DeskPage::class) ?? $page(ParticipationPage::class)), 'Pemilihan dimulai; voting bisa dibuka dan ditutup.'],
            [$page(ResultScreen::class), 'Pemilihan ditutup; hasil boleh ditampilkan.'],
            [$page(VerificationDesk::class), 'Panitia menetapkan yang terpilih/lolos.'],
            [$election->status === ElectionStatus::Published ? route('public.show', $election->public_id) : null, 'Hasil tampil di halaman publik.'],
        ];

        return collect(self::STAGES)
            ->map(fn (string $label, int $index): array => [
                'label' => $label,
                'url' => $index <= $current ? $targets[$index][0] : null,
                'description' => $targets[$index][1],
            ])
            ->all();
    }

    /**
     * Ringkasan isi persiapan agar terlihat apa yang sudah disiapkan.
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function preparationSummary(Election $election): array
    {
        $summary = [
            ['label' => 'Surat suara', 'value' => $election->ballots()->count()],
            ['label' => 'Calon', 'value' => $election->ballots()->withCount('ballotCandidates')->get()->sum('ballot_candidates_count')],
        ];

        if ($election->mode === ElectionMode::Dadakan) {
            $summary[] = ['label' => 'Panitia & petugas', 'value' => $election->staff()->count()];
            $summary[] = ['label' => 'Peserta hadir', 'value' => $election->attendees()->count()];
        } else {
            $user = $this->user();
            $summary[] = [
                'label' => $user->isSuperAdmin() ? 'Pemilih terdaftar' : 'Pemilih terdaftar RT Anda',
                'value' => Voter::query()->when(! $user->isSuperAdmin(), fn (Builder $query) => $query->where('unit_id', $user->unit_id))->count(),
            ];
        }

        return $summary;
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

        // Yang tidak bisa mengelola pemilihan (Panitia, Petugas Pintu, Admin RT) tidak diberi petunjuk tombol yang tidak mereka punya.
        $manager = ElectionResource::canAccess();
        $startedBy = $manager ? 'buka halaman pemilihan lalu klik Mulai Pemilihan.' : 'pemilihan dimulai oleh Super Admin.';

        [$hint, $options] = match ($election->status) {
            ElectionStatus::Draft => [$manager
                ? 'Isi surat suara dan calon. Setelah lengkap, buka halaman pemilihan lalu klik Tandai Siap.'
                : 'Super Admin sedang menyiapkan surat suara dan calon. Menu Anda aktif setelah pemilihan ditandai Siap.', [
                    fn () => $edit('Buka halaman pemilihan'),
                    fn () => CandidateResource::canAccess() ? ['Isi calon', CandidateResource::getUrl()] : null,
                ]],
            ElectionStatus::Ready => $dadakan
                ? ['Sudah siap. Petugas pintu sudah bisa mendata yang datang. Saat acara dimulai: '
                    .($manager ? 'buka Ruang Kendali, klik Mulai Pemilihan, lalu BUKA VOTING.' : 'pemilihan dimulai oleh Super Admin.'), [
                        fn () => $page(ControlRoom::class, $manager ? 'Buka Ruang Kendali (Mulai Pemilihan)' : 'Buka Ruang Kendali'),
                        fn () => $page(DoorDesk::class, 'Buka Meja Pintu'),
                    ]]
                : [$manager
                    ? 'Sudah siap. Pasang laptop meja dan bilik dengan token, lalu buka halaman pemilihan dan klik Mulai Pemilihan.'
                    : 'Sudah siap. Laptop meja dan bilik dipasang Super Admin; '.$startedBy, [
                        fn () => $page(DevicesPage::class, 'Pasang laptop (Perangkat)'),
                        fn () => $edit('Buka halaman pemilihan (Mulai Pemilihan)'),
                    ]],
            ElectionStatus::Berlangsung, ElectionStatus::Paused => $dadakan
                ? [ControlRoom::canAccess()
                    ? 'Pemilihan berjalan. Buka/tutup voting dan tampilkan QR dari Ruang Kendali.'
                    : 'Pemilihan berjalan. Terus data yang datang di Meja Pintu. Warga yang lupa PIN diarahkan ke meja panitia.', [
                        fn () => $page(ControlRoom::class, 'Buka Ruang Kendali'),
                        fn () => $page(DoorDesk::class, 'Buka Meja Pintu'),
                        fn () => $edit('Status: Jeda / Tutup Pemilihan'),
                    ]]
                : ['Pemilihan berjalan. Petugas meja mengizinkan pemilih ke bilik.', [
                    fn () => $page(DeskPage::class, 'Buka Meja Izin'),
                    fn () => $page(ParticipationPage::class, 'Lihat Partisipasi'),
                    fn () => $edit('Status: Jeda / Tutup Pemilihan'),
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
                [CandidateResource::class, 'Masukkan calon', 'Satu per satu atau Tambah banyak calon (tempel daftar nama).'],
                [GalleryPage::class, 'Galeri Foto: unggah foto calon', 'Lalu Pasang otomatis (nama file) atau pilih per calon.'],
                [ElectionResource::class, 'Tugaskan Panitia dan Petugas Pintu', 'Di pemilihan, tab Panitia & Petugas Pintu. Lalu klik Tandai Siap, dan saat acara dimulai klik Mulai Pemilihan.'],
                [DoorDesk::class, 'Meja Pintu: daftarkan yang hadir', 'Ketik nama, tekan Daftarkan & buat PIN, tulis PIN di kertas.'],
                [ControlRoom::class, 'Ruang Kendali: buka voting', 'Tampilkan Layar QR, tekan BUKA VOTING. Tabel peserta di bawahnya menunjukkan siapa yang belum memilih; mereka ikut sesi berikutnya.'],
                [ResultScreen::class, 'Tutup, lalu Tampilkan Hasil', 'Ranking calon tampil di layar/proyektor.'],
                [VerificationDesk::class, 'Tetapkan yang lolos, lalu Publish', 'Nama yang lolos tampil di halaman publik.'],
            ],
            ElectionMode::Resmi => [
                [UnitResource::class, 'Cek Daftar RT', 'Tambah RT bila jumlahnya lebih dari yang ada (bawaan 9).'],
                [ElectionResource::class, 'Buat pemilihan', 'Surat suara Ketua RT (per RT, maks. 5 calon) dan Ketua RW (semua RT).'],
                [CandidateResource::class, 'Masukkan calon RT tiap RT dan calon RW', ''],
                [GalleryPage::class, 'Galeri Foto: unggah dan pasang foto calon', ''],
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
