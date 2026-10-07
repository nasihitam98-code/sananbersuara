<?php

namespace App\Http\Controllers;

use App\Enums\ElectionMode;
use App\Filament\Support\Workspace;
use App\Models\Election;
use Illuminate\Http\RedirectResponse;

/**
 * Pilih/ganti mode kerja dan pemilihan yang sedang dikerjakan (hanya menata menu; hak akses tetap dicek tiap halaman).
 */
class WorkspaceController extends Controller
{
    public function __invoke(string $mode = 'pilih'): RedirectResponse
    {
        Workspace::choose(match ($mode) {
            'dadakan' => ElectionMode::Dadakan,
            'resmi' => ElectionMode::Resmi,
            default => null,
        });

        return redirect()->to(url('/admin'));
    }

    /**
     * Masuk ke satu pemilihan (tanpa argumen: kembali ke daftar pemilihan untuk memilih).
     */
    public function election(?Election $election = null): RedirectResponse
    {
        if ($election !== null && $election->mode !== Workspace::current()) {
            Workspace::choose($election->mode);
        }

        Workspace::chooseElection($election);

        return redirect()->to(url('/admin'));
    }
}
