<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\AuditLogger;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Pengaturan Tampilan (Super Admin): nama aplikasi, nama wilayah, dan pengumuman halaman publik,
 * agar teks bisa diubah tanpa menyentuh kode atau .env.
 *
 * @property-read Schema $form
 */
class SiteSettings extends Page
{
    protected string $view = 'filament.pages.site-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Data dasar';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Pengaturan Tampilan';

    protected static ?string $title = 'Pengaturan Tampilan';

    protected static ?string $slug = 'pengaturan-tampilan';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    public function mount(): void
    {
        $this->form->fill([
            AppSetting::SITE_NAME => AppSetting::get(AppSetting::SITE_NAME, config('app.name')),
            AppSetting::AREA_NAME => AppSetting::get(AppSetting::AREA_NAME),
            AppSetting::PUBLIC_NOTICE => AppSetting::get(AppSetting::PUBLIC_NOTICE),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Teks yang tampil di aplikasi')
                    ->description('Berlaku untuk panel admin, halaman publik, HP pemilih, laptop bilik, dan email.')
                    ->schema([
                        TextInput::make(AppSetting::SITE_NAME)
                            ->label('Nama aplikasi')
                            ->helperText('Judul di pojok kiri atas panel dan di halaman publik.')
                            ->placeholder('Contoh: Pemilihan Warga RW 05')
                            ->required()
                            ->maxLength(60),
                        TextInput::make(AppSetting::AREA_NAME)
                            ->label('Nama wilayah (opsional)')
                            ->helperText('Tampil kecil di atas judul halaman publik.')
                            ->placeholder('Contoh: RW 05 Kelurahan Sukamaju')
                            ->maxLength(100),
                        Textarea::make(AppSetting::PUBLIC_NOTICE)
                            ->label('Pengumuman di halaman publik (opsional)')
                            ->helperText('Mis. jadwal pemilihan atau nomor kontak panitia. Kosongkan bila tidak ada.')
                            ->rows(3)
                            ->maxLength(500),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $before = AppSetting::allValues();

        AppSetting::put([
            AppSetting::SITE_NAME => $data[AppSetting::SITE_NAME] ?? null,
            AppSetting::AREA_NAME => $data[AppSetting::AREA_NAME] ?? null,
            AppSetting::PUBLIC_NOTICE => $data[AppSetting::PUBLIC_NOTICE] ?? null,
        ]);

        app(AuditLogger::class)->log('settings.display_updated', meta: ['before' => $before, 'after' => AppSetting::allValues()]);

        Notification::make()->title('Pengaturan tampilan disimpan.')->success()->send();

        $this->redirect(static::getUrl());
    }
}
