<?php

namespace App\Providers\Filament;

use App\Enums\ElectionMode;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Elections\ElectionResource;
use App\Filament\Support\Workspace;
use App\Http\Middleware\EnsurePasswordChanged;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName(fn (): string => config('app.name'))
            ->login()
            ->profile(EditProfile::class, isSimple: false)
            ->multiFactorAuthentication(
                config('voting.admin_two_factor') ? [EmailAuthentication::make()] : [],
                isRequired: (bool) config('voting.admin_two_factor'),
            )
            ->colors([
                'primary' => Color::Indigo,
                'info' => Color::Blue,
                'success' => Color::Teal,
                'warning' => Color::Amber,
                'danger' => Color::Red,
                'gray' => Color::Slate,
            ])
            ->navigationGroups([
                NavigationGroup::make(Workspace::ELECTION_MENU_GROUP),
                NavigationGroup::make('Persiapan'),
                NavigationGroup::make('Hari H'),
                NavigationGroup::make('Hasil'),
                NavigationGroup::make('Lainnya')->collapsed(),
            ])
            ->navigationItems([
                NavigationItem::make('Halaman Publik')
                    ->url(fn (): string => route('public.index'), shouldOpenInNewTab: true)
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->sort(1),
                // Mode Dadakan: halaman pemilihan yang sedang dikerjakan (surat suara, panitia, riwayat).
                NavigationItem::make('Pengaturan pemilihan')
                    ->group(Workspace::ELECTION_MENU_GROUP)
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->sort(1)
                    ->visible(fn (): bool => Workspace::current() === ElectionMode::Dadakan && Workspace::election() !== null && ElectionResource::canAccess())
                    ->url(fn (): string => ($election = Workspace::election()) !== null ? ElectionResource::getUrl('edit', ['record' => $election]) : url('/admin'))
                    ->isActiveWhen(fn (): bool => request()->routeIs(ElectionResource::getRouteBaseName().'.edit')),
            ])
            ->sidebarCollapsibleOnDesktop()
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn (): View => view('filament.workspace-switcher'))
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsurePasswordChanged::class,
            ]);
    }
}
