<?php

namespace App\Providers\Filament;

use App\Filament\Logistics\Pages\LogisticsDashboard;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The freight console.
 *
 * A second panel rather than a page in the first, because the two consoles
 * have different navigation and different default screens: freight is grouped
 * by lane, the auction by market and money. Same guard as the operations
 * panel, so one sign-in covers both.
 */
class LogisticsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('logistics')
            ->path('admin/logistics')
            ->login()
            ->authGuard('admin')
            ->viteTheme('resources/css/filament/theme.css')
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
            ->discoverResources(
                in: app_path('Filament/Logistics/Resources'),
                for: 'App\Filament\Logistics\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/Logistics/Pages'),
                for: 'App\Filament\Logistics\Pages',
            )
            ->pages([
                LogisticsDashboard::class,
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
