<?php

namespace App\Http\Controllers;

use App\Enums\ElectionMode;
use App\Filament\Support\Workspace;
use Illuminate\Http\RedirectResponse;

/**
 * Pilih/ganti mode kerja panel (hanya menata menu; hak akses tetap dicek tiap halaman).
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
}
