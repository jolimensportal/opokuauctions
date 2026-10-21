<?php

namespace App\Filament\Logistics\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class LogisticsDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Logistics';

    protected static string|UnitEnum|null $navigationGroup = 'Watch';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Logistics';

    /**
     * The console's front door is the console's own URL. Filament's panel
     * root otherwise redirects to the first page's slug, which would put the
     * home screen at /admin/logistics/logistics-dashboard.
     */
    protected static ?string $slug = '/';

    protected string $view = 'filament.logistics.pages.logistics-dashboard';

    public function getViewData(): array
    {
        return [];
    }
}
