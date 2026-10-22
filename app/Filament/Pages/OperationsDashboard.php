<?php

namespace App\Filament\Pages;

use App\Services\OperationsOverview;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class OperationsDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Dashboard';

    protected static string|UnitEnum|null $navigationGroup = 'Marketplace';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Operations';

    /**
     * The console's front door is the console's own URL. Filament's panel
     * root otherwise redirects to the first page's slug.
     */
    protected static ?string $slug = '/';

    protected string $view = 'filament.pages.operations-dashboard';

    /**
     * Thin, like the freight console. The numbers come from
     * OperationsOverview, which is tested without a browser.
     */
    public function getViewData(): array
    {
        $overview = app(OperationsOverview::class);

        return [
            'summary' => $overview->summary(),
            'activity' => $overview->activity(),
            'actions' => $overview->actions(),
            'moneyAtRisk' => $overview->moneyAtRisk(),
        ];
    }
}
