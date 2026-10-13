<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SiteLogoProcessor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use RuntimeException;
use UnitEnum;

/**
 * Pengaturan Tampilan (Super Admin): nama aplikasi, nama wilayah, pengumuman halaman publik, dan logo,
 * agar tampilan bisa diubah tanpa menyentuh kode atau .env.
 *
 * @property-read Schema $form
 */
class SiteSettings extends Page
{
    protected string $view = 'filament.pages.site-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Lainnya';

    protected static ?int $navigationSort = 4;

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

        Notification::make()->title('Teks tampilan disimpan.')->success()->send();

        $this->redirect(static::getUrl());
    }

    /**
     * "Ganti logo": jendela unggah dengan pratinjau kecil; tersimpan langsung (terpisah dari Simpan teks).
     */
    public function uploadLogoAction(): Action
    {
        return Action::make('uploadLogo')
            ->label(fn (): string => AppSetting::logoUrl() === null ? 'Unggah logo' : 'Ganti logo')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Unggah logo')
            ->modalDescription('Logo tampil di halaman publik untuk semua orang. Pastikan yang diunggah memang logo, bukan foto atau tangkapan layar.')
            ->modalSubmitActionLabel('Pakai logo ini')
            ->modalWidth(Width::Large)
            ->schema([
                FileUpload::make('logo')
                    ->label('Berkas logo')
                    ->helperText('PNG, JPG, atau WebP, maks. 2 MB. Paling bagus logo persegi berlatar transparan (PNG). Diperkecil otomatis tanpa dipotong.')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(2048)
                    ->imagePreviewHeight('160')
                    ->disk('local')
                    ->directory('unggahan-sementara')
                    ->visibility('private')
                    ->required(),
            ])
            ->action(function (array $data): void {
                try {
                    app(SiteLogoProcessor::class)->replace($data['logo']);
                } catch (RuntimeException $exception) {
                    Notification::make()->title('Logo tidak bisa dipakai')->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Logo dipasang.')->body('Sudah tampil di semua halaman.')->success()->send();
                // Muat ulang agar logo di header panel ikut berganti.
                $this->redirect(static::getUrl());
            });
    }

    /**
     * "Hapus logo": kembali ke kotak merah bercentang bawaan. Hanya tampil bila ada logo unggahan.
     */
    public function removeLogoAction(): Action
    {
        return Action::make('removeLogo')
            ->label('Hapus logo')
            ->icon(Heroicon::OutlinedTrash)
            ->color('gray')
            ->visible(fn (): bool => AppSetting::logoUrl() !== null)
            ->requiresConfirmation()
            ->modalIconColor('danger')
            ->modalHeading('Hapus logo?')
            ->modalDescription('Semua halaman kembali memakai logo bawaan (kotak merah bercentang). Berkas logo dihapus.')
            ->modalSubmitActionLabel('Ya, hapus logo')
            ->modalSubmitAction(fn (Action $action): Action => $action->color('danger'))
            ->action(function (): void {
                app(SiteLogoProcessor::class)->remove();

                Notification::make()->title('Logo dihapus, kembali ke logo bawaan.')->success()->send();
                $this->redirect(static::getUrl());
            });
    }
}
