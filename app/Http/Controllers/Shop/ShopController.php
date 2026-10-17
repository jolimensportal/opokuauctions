<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Bid;
use App\Models\ClearanceTask;
use App\Models\Lot;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use App\Services\ClearanceService;
use App\Services\FacetService;
use App\Services\QuoteService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The buyer-facing shop.
 *
 * Deliberately thin: it reads models and passes them to views. All the rules
 * live in services that are tested without a browser, because a controller that
 * holds logic is logic that cannot be tested cheaply.
 */
class ShopController extends Controller
{
    public function home(FacetService $facets): View
    {
        return view('shop.home', [
            'featuredLots' => $this->openLots(),
            'conditions' => $this->conditions(),
            'liveTotal' => Lot::where('status', 'LIVE')->count(),
            'facets' => [
                'totalOpen' => $facets->totalOpen(),
                'yards' => $facets->yardCount(),
                'makes_count' => $facets->makeCount(),
                'makes' => $facets->byMake(),
                'conditions' => $facets->byCondition(),
                'yards_list' => $facets->byCity(),
                'price_bands' => $facets->byPriceBand(),
                'closing' => $facets->byClosingSoon(),
                'years' => $facets->byYear(),
                'mileage' => $facets->byMileage(),
            ],
            'landedExample' => $this->landedExample(),
        ]);
    }

    /**
     * The four conditions a lot can carry, with the plain-language meaning
     * buyers actually need. The copy is the sales argument: a buyer who does not
     * know what "project" means cannot bid, and a wrong guess costs them the car.
     *
     * @return array<int, array{key:string, label:string, blurb:string}>
     */
    private function conditions(): array
    {
        return [
            [
                'key' => 'clean_title',
                'label' => 'Clean title',
                'blurb' => 'No accident damage on record. Drives off the yard once it clears.',
            ],
            [
                'key' => 'salvage',
                'label' => 'Salvage',
                'blurb' => 'Damage on record, usually repairable. We photograph the damage before you bid.',
            ],
            [
                'key' => 'project',
                'label' => 'Project',
                'blurb' => 'Needs work to be roadworthy. Buyers who intend to repair it, not drive it away.',
            ],
            [
                'key' => 'parts_only',
                'label' => 'Parts only',
                'blurb' => 'Not roadworthy. Bought for spares. Cannot be registered for road use.',
            ],
        ];
    }

    /**
     * One worked example of what a vehicle costs landed, taken from a real
     * shipment's cost lines rather than invented. Returns null when there is no
     * shipment allocated yet, so the section is omitted instead of showing
     * placeholder zeros.
     */
    private function landedExample(): ?array
    {
        $shipment = Shipment::whereHas('lots')->latest('created_at')->first();

        if (! $shipment) {
            return null;
        }

        $breakdown = app(ClearanceService::class)->landedCostBreakdown($shipment->id);

        if ($breakdown === []) {
            return null;
        }

        return [
            'shipment' => $shipment,
            'freight' => (int) $shipment->freight_cost,
            'insurance' => (int) $shipment->insurance_cost,
            'clearance' => (int) ClearanceTask::where('shipment_id', $shipment->id)->sum('cost'),
            'share' => (int) $breakdown[0]['freight_and_clearance_share'],
            'vehicleCount' => count($breakdown),
            'example' => $breakdown[0],
        ];
    }

    private function openLots()
    {
        return Lot::whereIn('status', ['LIVE', 'UPCOMING'])
            ->orderBy('ends_at')
            ->limit(9)
            ->get();
    }

    public function lots(Request $request, FacetService $facets): View
    {
        $query = Lot::whereIn('status', ['LIVE', 'UPCOMING', 'SOLD'])
            ->latest('ends_at');

        // Filters are all optional and all additive; none of them is required to
        // see inventory, because a board you cannot browse is a dead board.
        if ($city = $request->string('city')->toString()) {
            $query->where('city', $city);
        }

        if ($condition = $request->string('condition')->toString()) {
            $query->where('condition', $condition);
        }

        // Make, kind and provenance are how a buyer narrows a board that now
        // holds cars and goods alike. Make is filtered through a subquery
        // rather than a join because the column now lives in vehicle_detail,
        // and joining would multiply rows against the paginator.
        if ($make = $request->string('make')->toString()) {
            $query->whereIn('id', function ($sub) use ($make) {
                $sub->select('lot_id')->from('vehicle_detail')->where('make', $make);
            });
        }

        if ($kind = $request->string('kind')->toString()) {
            $query->where('kind', $kind);
        }

        if ($provenance = $request->string('provenance')->toString()) {
            $query->where('provenance', $provenance);
        }

        $lots = $query->paginate(24)->withQueryString();

        $cities = Lot::distinct()->orderBy('city')->pluck('city');
        $makes = $facets->byMake(50)->pluck('value');

        return view('shop.lots.index', [
            'lots' => $lots,
            'cities' => $cities,
            'makes' => $makes,
            'filters' => $request->only(['city', 'condition', 'make', 'kind', 'provenance']),
        ]);
    }

    public function lot(FacetService $facets, QuoteService $quotes, Lot $lot): View
    {
        $landed = null;
        $link = ShipmentLot::where('lot_id', $lot->id)->first();

        if ($link !== null) {
            try {
                $rows = app(ClearanceService::class)->landedCostBreakdown($link->shipment_id);
                $row = collect($rows)->firstWhere('lot_id', $lot->id);

                if ($row !== null) {
                    $landed = [
                        'row' => $row,
                        'vehicleCount' => (int) ShipmentLot::where('shipment_id', $link->shipment_id)->sum('quantity'),
                    ];
                }
            } catch (\RuntimeException) {
                $landed = null;
            }
        }

        return view('shop.lots.show', [
            'lot' => $lot,
            'bids' => Bid::where('lot_id', $lot->id)
                ->where('status', '!=', 'RETRACTED')
                ->orderByDesc('amount')
                ->limit(8)
                ->get(),

            // Rendered server-side as bring-yourself, so the card is right with
            // no JavaScript at all. The toggle fetches the other case only when
            // a buyer actually clicks it.
            'freight' => $quotes->forLot($lot, false),
            'landed' => $landed,
        ]);
    }

    public function howItWorks(): View
    {
        return view('shop.how-it-works');
    }

    public function fees(): View
    {
        return view('shop.fees');
    }
}