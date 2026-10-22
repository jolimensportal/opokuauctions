<?php

namespace App\Filament\Logistics\Pages;

use App\Models\ParcelBooking;
use App\Models\Shipment;
use App\Services\LogisticsExceptions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class LogisticsDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Logistics';

    protected static string|UnitEnum|null $navigationGroup = 'Freight we carry';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Logistics';

    /**
     * Its own URL, inside the operations panel.
     *
     * This was a second panel, which gave each console its own navigation and
     * left an operator with no way from one to the other: the freight console
     * was only reachable by typing its URL, because Filament's navigation
     * belongs to the panel. One panel with grouped navigation gives the same
     * separation — the freight groups sit beside the marketplace ones — and
     * nothing is hidden.
     */
    protected static ?string $slug = 'logistics';

    protected string $view = 'filament.logistics.pages.logistics-dashboard';

    /**
     * Thin, like the corridor board.
     *
     * The queries live in LogisticsExceptions and ShipmentStaleness and are
     * tested there. A page that queries inline cannot be tested without a
     * browser, and these are the numbers an operator decides the day by.
     */
    public function getViewData(): array
    {
        $exceptions = app(LogisticsExceptions::class)->all();

        $shipments = Shipment::query()
            ->with(['forwarder', 'milestones'])
            ->orderByDesc('eta')
            ->get();

        return [
            'counters' => [
                'inTransit' => $shipments->filter(
                    fn (Shipment $shipment): bool => in_array($shipment->status, ['LOADED', 'DEPARTED', 'SAILING'], true)
                )->count(),
                'atTema' => $shipments->filter(
                    fn (Shipment $shipment): bool => in_array($shipment->status, ['ARRIVED', 'CLEARED'], true)
                )->count(),
                'parcelsBooked' => ParcelBooking::query()
                    ->where('status', 'not', 'payment_pending')
                    ->count(),
                'needAttention' => count($exceptions),
            ],
            'exceptions' => $exceptions,
            'shipments' => $shipments,
        ];
    }
}
