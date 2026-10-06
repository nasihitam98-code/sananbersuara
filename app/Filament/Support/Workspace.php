<?php

namespace App\Filament\Support;

use App\Enums\ElectionMode;
use App\Models\User;

/**
 * Mode kerja panel (Dadakan / Resmi) agar menu hanya berisi yang relevan.
 * Super Admin memilih sendiri (disimpan di sesi); staf pemilihan otomatis Dadakan,
 * Admin RT otomatis Resmi. Ini hanya penataan menu, bukan batas akses: hak akses
 * tetap ditentukan canAccess() tiap halaman.
 */
class Workspace
{
    public const SESSION_KEY = 'workspace_mode';

    /**
     * @return array<int, ElectionMode>
     */
    public static function allowedModes(?User $user = null): array
    {
        $user ??= auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        if ($user->isSuperAdmin()) {
            return [ElectionMode::Dadakan, ElectionMode::Resmi];
        }

        $modes = [];

        if ($user->electionAssignments()->exists()) {
            $modes[] = ElectionMode::Dadakan;
        }

        if ($user->isAdminRt()) {
            $modes[] = ElectionMode::Resmi;
        }

        return $modes;
    }

    public static function current(): ?ElectionMode
    {
        $allowed = static::allowedModes();

        if (count($allowed) === 1) {
            return $allowed[0];
        }

        $chosen = ElectionMode::tryFrom((string) session(static::SESSION_KEY));

        return in_array($chosen, $allowed, true) ? $chosen : null;
    }

    public static function canSwitch(): bool
    {
        return count(static::allowedModes()) > 1;
    }

    public static function choose(?ElectionMode $mode): void
    {
        if ($mode === null) {
            session()->forget(static::SESSION_KEY);

            return;
        }

        abort_unless(in_array($mode, static::allowedModes(), true), 403);

        session([static::SESSION_KEY => $mode->value]);
    }

    /**
     * Menu tampil hanya bila mode kerja sekarang termasuk salah satu mode ini
     * (tanpa argumen: tampil bila sudah ada mode terpilih).
     */
    public static function shows(ElectionMode ...$modes): bool
    {
        $current = static::current();

        return $current !== null && ($modes === [] || in_array($current, $modes, true));
    }
}
