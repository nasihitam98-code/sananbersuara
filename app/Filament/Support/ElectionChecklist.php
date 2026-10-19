<?php

namespace App\Filament\Support;

use App\Enums\BallotScope;
use App\Enums\ElectionStatus;
use App\Enums\StaffRole;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Filament\Resources\Elections\ElectionResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Voters\VoterResource;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Unit;
use App\Models\User;
use App\Models\Voter;

/**
 * Daftar periksa persiapan di halaman pemilihan: tiap langkah berstatus selesai/belum,
 * keterangan singkat, dan tombol ke tempat mengerjakannya. Langkah menyesuaikan mode.
 */
class ElectionChecklist
{
    /**
     * @return array<int, array{title: string, done: bool, detail: string, actionLabel: ?string, url: ?string}>
     */
    public static function steps(Election $election): array
    {
        $ballots = $election->ballots()->withCount('ballotCandidates')->get();
        $candidates = Candidate::query()->whereIn('ballot_id', $ballots->pluck('id'))->get(['id', 'ballot_id', 'unit_id', 'photo_key']);
        // #kelola: layar langsung bergulir ke tabel tab itu (penanda dipasang di AdminPanelProvider).
        $editUrl = fn (int $tab): string => ElectionResource::getUrl('edit', ['record' => $election, 'relation' => (string) $tab]).'#kelola';
        $withoutPhoto = $candidates->whereNull('photo_key')->count();

        $steps = [[
            'title' => 'Surat suara',
            'done' => $ballots->isNotEmpty(),
            'detail' => $ballots->isEmpty()
                ? ($election->isDadakan() ? 'Belum ada. Tambah satu, mis. "Calon Ketua RW".' : 'Belum ada. Tambah "Ketua RT" (per RT) dan "Ketua RW" (semua RT).')
                : $ballots->count().' surat suara: '.$ballots->pluck('title')->implode(', '),
            'actionLabel' => 'Kelola surat suara',
            'url' => $editUrl(0),
        ]];

        $candidateDetail = $candidates->isEmpty()
            ? 'Belum ada calon.'
            : $candidates->count().' calon'.($withoutPhoto > 0 ? " ({$withoutPhoto} belum berfoto)" : ', semua berfoto');

        if (! $election->isDadakan()) {
            $perRtBallot = $ballots->first(fn ($ballot): bool => $ballot->scope === BallotScope::PerRt);

            if ($perRtBallot !== null) {
                $missing = Unit::query()->whereNotIn('id', $candidates->where('ballot_id', $perRtBallot->id)->pluck('unit_id')->filter())->orderBy('sort')->pluck('name');

                if ($missing->isNotEmpty()) {
                    $candidateDetail = rtrim($candidateDetail, '.').'. Belum ada calon RT untuk: '.$missing->implode(', ');
                }
            }
        }

        $steps[] = [
            'title' => 'Calon',
            'done' => $ballots->isNotEmpty() && $ballots->every(fn ($ballot): bool => $ballot->ballot_candidates_count > 0),
            'detail' => $candidateDetail,
            'actionLabel' => 'Kelola calon',
            'url' => $ballots->count() === 1
                ? CandidateResource::getUrl('index', ['filters' => ['ballot_id' => ['value' => $ballots->first()->id]]])
                : CandidateResource::getUrl('index'),
        ];

        if ($election->isDadakan()) {
            $roles = $election->staff()->pluck('role');
            $committee = $roles->filter(fn ($role): bool => $role === StaffRole::Panitia)->count();
            $door = $roles->filter(fn ($role): bool => $role === StaffRole::PetugasPintu)->count();

            // Opsional: Super Admin bisa menjalankan Meja Pintu dan Ruang Kendali sendiri, jadi tidak menghalangi Tandai Siap.
            $steps[] = [
                'title' => 'Panitia & Petugas Pintu (opsional)',
                'done' => true,
                'detail' => "{$committee} Panitia, {$door} Petugas Pintu. Tugaskan bila ada panitia lain yang memakai akun sendiri; Super Admin bisa mengerjakan semuanya tanpa ditugaskan.",
                'actionLabel' => 'Tugaskan',
                'url' => $editUrl(1),
            ];
        } else {
            $voters = Voter::query()->count();
            $deskOfficers = User::role(User::ROLE_ADMIN_RT)->permission(User::PERMISSION_DESK)->count();

            $steps[] = [
                'title' => 'Data pemilih',
                'done' => $voters > 0,
                'detail' => $voters > 0 ? "{$voters} pemilih terdaftar." : 'Belum ada. Isi per RT atau import Excel.',
                'actionLabel' => 'Data pemilih',
                'url' => VoterResource::getUrl('index'),
            ];
            $steps[] = [
                'title' => 'Akun petugas meja (Admin RT)',
                'done' => $deskOfficers > 0,
                'detail' => $deskOfficers > 0 ? "{$deskOfficers} akun petugas meja." : 'Belum ada. Buat akun Admin RT dengan izin Petugas Meja.',
                'actionLabel' => 'Akun',
                'url' => UserResource::getUrl('index'),
            ];
        }

        // Opsional (tidak menghalangi Tandai Siap): tampil di halaman publik, visi & misi, dan quick count, lewat tombol di kanan atas.
        $quickCount = (bool) $election->setting('public_quick_count');
        $profiles = (bool) $election->setting('public_profiles')
            ? ' Visi & misi: tombol Detail per calon tampil.'
            : ' Visi & misi: tidak tampil (tombol "Visi & misi publik" di kanan atas).';
        $steps[] = [
            'title' => 'Halaman publik & quick count (opsional)',
            'done' => true,
            'detail' => match (true) {
                ! $election->isPublic() => 'Tidak tampil di halaman publik. Tekan "Tidak tampil di halaman publik" di kanan atas untuk menampilkannya; setelah itu tombol Visi & misi publik dan Quick count muncul.',
                $quickCount => 'Tampil di halaman publik: foto, nomor, dan nama calon sejak Siap; quick count aktif (angka sementara tiap 10 menit selama voting).'.$profiles,
                default => 'Tampil di halaman publik: foto, nomor, dan nama calon sejak Siap; quick count mati (angka hanya tampil setelah diumumkan). Untuk menyalakan: tombol "Quick count: mati" di kanan atas.'.$profiles,
            },
            'actionLabel' => null,
            'url' => null,
        ];

        $ready = $election->status !== ElectionStatus::Draft;
        $prepared = collect($steps)->every(fn (array $step): bool => $step['done']);

        $steps[] = [
            'title' => 'Tandai Siap',
            'done' => $ready,
            'detail' => match (true) {
                $ready && $election->isDadakan() => 'Sudah Siap. Petugas pintu sudah bisa mendata. Saat acara dimulai: tombol Mulai Pemilihan di kanan atas.',
                $ready => 'Sudah Siap. Pasang laptop meja dan bilik di menu Perangkat, lalu Mulai Pemilihan di kanan atas.',
                $prepared => 'Semua langkah di atas lengkap. Tekan tombol Tandai Siap di kanan atas.',
                default => 'Lengkapi langkah di atas dulu, lalu tekan Tandai Siap di kanan atas.',
            },
            'actionLabel' => null,
            'url' => null,
        ];

        return $steps;
    }
}
