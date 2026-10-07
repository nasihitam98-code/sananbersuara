<?php

namespace App\Filament\Support;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Models\Election;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;

/**
 * Mode kerja panel (Dadakan / Resmi) agar menu hanya berisi yang relevan.
 * Super Admin memilih sendiri (disimpan di sesi); staf pemilihan otomatis Dadakan,
 * Admin RT otomatis Resmi. Ini hanya penataan menu, bukan batas akses: hak akses
 * tetap ditentukan canAccess() tiap halaman.
 */
class Workspace
{
    public const SESSION_KEY = 'workspace_mode';

    public const ELECTION_SESSION_KEY = 'workspace_election';

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

        $chosen = ElectionMode::tryFrom((string) (session(static::SESSION_KEY) ?? request()->cookie(static::SESSION_KEY)));

        return in_array($chosen, $allowed, true) ? $chosen : null;
    }

    public static function canSwitch(): bool
    {
        return count(static::allowedModes()) > 1;
    }

    public static function choose(?ElectionMode $mode): void
    {
        session()->forget(static::ELECTION_SESSION_KEY);

        if ($mode === null) {
            session()->forget(static::SESSION_KEY);
            Cookie::queue(Cookie::forget(static::SESSION_KEY));

            return;
        }

        abort_unless(in_array($mode, static::allowedModes(), true), 403);

        session([static::SESSION_KEY => $mode->value]);
        // Diingat di browser ini agar tidak perlu memilih ulang setiap login.
        Cookie::queue(static::SESSION_KEY, $mode->value, 60 * 24 * 365);
    }

    /**
     * Pemilihan yang boleh dilihat pengguna pada mode kerja ini (belum dibatalkan/diarsipkan), terbaru dulu.
     * Super Admin: semua; staf: yang ditugaskan (Dadakan); Admin RT: semua pemilihan Resmi.
     *
     * @return Collection<int, Election>
     */
    public static function elections(?User $user = null): Collection
    {
        $user ??= auth()->user();
        $mode = static::current();

        if (! $user instanceof User || $mode === null) {
            return collect();
        }

        return Election::query()
            ->where('mode', $mode)
            ->whereNotIn('status', [ElectionStatus::Cancelled, ElectionStatus::Archived])
            ->when(! $user->isSuperAdmin() && $mode === ElectionMode::Dadakan, fn (Builder $query) => $query
                ->whereHas('staff', fn (Builder $staff) => $staff->where('user_id', $user->id)))
            ->latest('id')
            ->get();
    }

    /**
     * Pemilihan yang sedang dikerjakan: semua menu (Calon, Meja Pintu, Ruang Kendali, Hasil) mengikuti
     * pemilihan ini. Super Admin memilih sendiri di Beranda; staf yang hanya punya satu pemilihan langsung
     * masuk ke pemilihan itu. Ini hanya fokus tampilan, bukan status pemilihan dan bukan batas akses.
     */
    public static function election(): ?Election
    {
        $user = auth()->user();
        $elections = static::elections();
        $chosen = session(static::ELECTION_SESSION_KEY);
        $focused = $chosen === null ? null : $elections->firstWhere('public_id', $chosen);

        if ($focused !== null || ! $user instanceof User || $user->isSuperAdmin()) {
            return $focused;
        }

        return $elections->count() === 1 ? $elections->first() : null;
    }

    /**
     * Pilihan pemilihan baru perlu dibuat lewat Beranda bila ada lebih dari satu (atau pengguna Super Admin).
     */
    public static function canChooseElection(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->isSuperAdmin() || static::elections()->count() > 1);
    }

    public static function chooseElection(?Election $election): void
    {
        if ($election === null) {
            session()->forget(static::ELECTION_SESSION_KEY);

            return;
        }

        abort_unless(static::elections()->contains(fn (Election $visible): bool => $visible->is($election)), 403);

        session([static::ELECTION_SESSION_KEY => $election->public_id]);
    }

    /**
     * Pemilihan lain (bukan yang sedang dikerjakan) yang sedang berlangsung, untuk peringatan.
     */
    public static function otherLiveElection(?Election $except = null): ?Election
    {
        return static::elections()->first(fn (Election $election): bool => $election->status->isLive() && ! $election->is($except));
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
