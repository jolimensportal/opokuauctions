<?php

namespace App\Providers\Filament\Concerns;

use Filament\Support\Assets\Theme;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Foundation\Vite;

/**
 * The panel's stylesheet: Filament's own, and then ours.
 *
 * ->viteTheme() does not ADD a stylesheet to the panel, it REPLACES the
 * panel's theme with it. Using it on its own leaves the console with none
 * of Filament's own CSS: every icon renders at its natural size, the layout
 * falls apart, and nothing in the test suite notices, because the page
 * still returns 200.
 *
 * So the theme is composed: Filament's stylesheet first, ours second, so
 * ours can override it rather than replace it.
 */
trait HasOpokuTheme
{
    protected function opokuTheme(): Theme
    {
        return Theme::make('opoku')
            ->html(fn (): string => implode('', [
                FilamentAsset::getTheme('app')?->getHtml()->toHtml() ?? '',
                app(Vite::class)('resources/css/filament/theme.css')->toHtml(),
            ]));
    }
}
