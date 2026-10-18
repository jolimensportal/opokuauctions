<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Lot;
use App\Models\Shipment;
use App\Models\Wallet;
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
            'tiles' => $this->tiles($userId, $wonLots),
        ]);
    }

    /**
     * The four figures across the top of the dashboard.
     *
     * Parcels sent is always zero until sender identity exists. It is shown as
     * a real zero rather than omitted: a tile that is silently missing reads as
     * "not applicable", and a real zero reads as "you have sent none", which
     * is a different and truthful message.
     */
    private function tiles(string $userId, $wonLots): array
    {
        $shipments = $this->shipmentsFor($userId);

        return [
            'wonThisMonth' => $wonLots->filter(
                fn (Lot $lot) => $lot->ends_at?->isCurrentMonth() ?? false,
            )->count(),
            'inTransit' => $shipments->filter(
                fn (Shipment $s) => in_array($s->status, ['BOOKING', 'SAILING'], true),
            )->count(),
            // Zero until the logistics portal can book a parcel.
            'parcelsSent' => 0,
            'balanceCents' => (int) Wallet::where('user_id', $userId)
                ->where('currency_code', 'USD')
                ->value('balance_available'),
        ];
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