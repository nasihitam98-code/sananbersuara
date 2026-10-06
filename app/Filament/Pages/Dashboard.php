<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\HomeGuide;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Beranda panel: status pemilihan + langkah berikutnya + panduan per mode, sesuai peran pengguna.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Beranda';

    protected static ?string $navigationLabel = 'Beranda';

    public function getWidgets(): array
    {
        return [HomeGuide::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
