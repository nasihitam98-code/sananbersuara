<?php

namespace App\Filament\Support;

use App\Enums\ElectionMode;
use UnitEnum;

/**
 * Menu milik satu pemilihan. Di Mode Dadakan semuanya dikumpulkan di grup "Pemilihan ini" dengan urutan
 * kerja (pengaturan, calon, pintu, kendali, hasil, verifikasi); Mode Resmi tetap memakai grup tahapan.
 * Kelas pemakai mendefinisikan ELECTION_MENU_SORT.
 */
trait InElectionMenu
{
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return Workspace::current() === ElectionMode::Dadakan ? Workspace::ELECTION_MENU_GROUP : parent::getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return Workspace::current() === ElectionMode::Dadakan ? static::ELECTION_MENU_SORT : parent::getNavigationSort();
    }
}
