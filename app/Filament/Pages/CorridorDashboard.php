<?php

namespace App\Filament\Pages;

use App\Services\CorridorQueries;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class CorridorDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Corridor';

    protected static string|UnitEnum|null $navigationGroup = 'Watch';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.corridor-dashboard';

    protected static ?string $title = 'Corridor';

    /**
     * Deliberately thin.
     *
     * All the logic lives in CorridorQueries and is tested there (13 tests).
     * A page that queries inline cannot be tested without a browser, and the
     * queues are the part of this product most likely to be subtly wrong.
     */
    public function getViewData(): array
    {
        $queries = app(CorridorQueries::class);

        return [
            'summary' => $queries->summary(),
            'needsMoney' => $queries->needsMoney(),
            'unallocated' => $queries->unallocated(),
            'needsBooking' => $queries->needsBooking(),
            'inTransit' => $queries->inTransit(),
            'atTema' => $queries->atTema(),
            'disputed' => $queries->disputed(),
            'drift' => $queries->reconciliationDrift(),
        ];
    }
}
