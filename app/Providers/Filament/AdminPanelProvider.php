<?php

namespace App\Providers\Filament;

use App\Providers\Filament\Concerns\HasOpokuTheme;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    use HasOpokuTheme;

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // The console signs in on its own guard, so a buyer and an
            // operator can be signed in at the same time in one browser.
            ->authGuard('admin')
            // Our design system, built by Vite. Without this the panel loads
            // only Filament's stylesheet and every class written in an admin
            // blade is inert.
            ->theme($this->opokuTheme())
            // The console is a light paper system, like the shop, and it
            // stays light. Filament follows the operating system by default,
            // which put a dark topbar and white-on-dark headings over our
            // light canvas. Dark mode is forced off because our cards are
            // paper — there is no dark variant of them to switch to, and the
            // switcher is hidden so nobody can reach one.
            ->defaultThemeMode(ThemeMode::Light)
            ->darkMode(false, isForced: true)
            ->themeSwitcher(false)
            ->font(
                'DM Sans',
                url: fn (): string => asset('fonts/filament/dm-sans/index.css'),
                provider: LocalFontProvider::class,
            )
            ->monoFont(
                'IBM Plex Mono',
                url: fn (): string => asset('fonts/filament/ibm-plex-mono/index.css'),
                provider: LocalFontProvider::class,
            )
            ->colors([
                // The shop's own brick ramp, step for step. Filament's amber
                // made the console look like a different product, and
                // Color::hex() would have generated a ramp that merely looks
                // close — one brand means one red, or the two drift.
                'primary' => [
                    50 => 'oklch(0.970 0.011 27)',
                    100 => 'oklch(0.940 0.028 27)',
                    200 => 'oklch(0.895 0.052 27)',
                    300 => 'oklch(0.820 0.098 27)',
                    400 => 'oklch(0.670 0.175 27)',
                    500 => 'oklch(0.565 0.205 27)',
                    600 => 'oklch(0.490 0.190 27)',
                    700 => 'oklch(0.405 0.155 27)',
                    800 => 'oklch(0.325 0.115 27)',
                    900 => 'oklch(0.255 0.085 27)',
                    // The shop's ramp stops at 900; Filament wants a 950, and
                    // this continues the same steps down.
                    950 => 'oklch(0.190 0.070 27)',
                ],
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
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
            ]);
    }
}
