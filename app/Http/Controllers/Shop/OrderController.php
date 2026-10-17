<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Lot;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What the buyer owns and where it is: won lots, in-transit shipments, and the
 * one shipment detail view.
 *
 * There is no buyer_id on shipment_lots. A buyer's claim on a shipment is
 * derived: they are the highest bidder on at least one lot inside it. Keeping
 * that derivation in one place means the account page and the detail page can
 * never disagree about who is allowed to see a container.
 */
class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $userId = (string) $request->user()->id;

        // Won lots: the buyer has a claim, but the vehicle is not theirs yet.
        $wonLots = Lot::where('highest_bidder_id', $userId)
            ->orderByDesc('ends_at')
            ->get();

        return view('shop.account', [
            'wonLots' => $wonLots,
            'shipments' => $this->shipmentsFor($userId),
        ]);
    }

    public function purchases(Request $request): View
    {
        return view('shop.orders', [
            'shipments' => $this->shipmentsFor((string) $request->user()->id),
        ]);
    }

    public function show(Request $request, Shipment $shipment): View
    {
        // Ownership check. Route-model binding finds the shipment; this decides
        // whether this particular buyer may read it. Getting it wrong would leak
        // another customer's container contents, so it fails closed.
        abort_unless($this->buyerOwnsShipment($shipment, (string) $request->user()->id), 404);

        $shipment->load([
            'lots.lot',
            'forwarder',
            'milestones' => fn ($q) => $q->orderByDesc('occurred_at'),
        ]);

        return view('shop.order-show', ['shipment' => $shipment]);
    }

    /** @return \Illuminate\Support\Collection<int, Shipment> */
    private function shipmentsFor(string $userId)
    {
        return Shipment::query()
            ->whereHas('lots.lot', fn ($lot) => $lot->where('highest_bidder_id', $userId))
            ->orderByDesc('created_at')
            ->get();
    }

    private function buyerOwnsShipment(Shipment $shipment, string $userId): bool
    {
        return $shipment->lots()
            ->whereHas('lot', fn ($lot) => $lot->where('highest_bidder_id', $userId))
            ->exists();
    }
}