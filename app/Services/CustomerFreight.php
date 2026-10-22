<?php

namespace App\Services;

use App\Enums\ParcelBookingStatus;
use App\Models\ClearanceTask;
use App\Models\Lot;
use App\Models\ParcelBooking;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use App\Models\User;

/**
 * What a buyer is waiting for.
 *
 * Their cars are the lots they won, joined to the container carrying them.
 * Their parcels are the bookings they sent: found by the user link when they
 * were signed in, and by email when they were not — a guest's booking has to
 * be findable too, or a buyer who sent a parcel before signing in would look
 * at an empty page and think it was lost.
 */
class CustomerFreight
{
    /**
     * @return array{
     *     cars: array<int, array<string, mixed>>,
     *     parcels: array<int, ParcelBooking>,
     *     actions: array<int, array{title: string, detail: string, action: string, tag: string, tone: string}>,
     *     counters: array{carsArriving: int, parcelsSent: int, balanceCents: int},
     *     freightBilledCents: int,
     * }
     */
    public function for(User $buyer): array
    {
        $cars = $this->cars($buyer);
        $parcels = $this->parcels($buyer);

        return [
            'cars' => $cars,
            'parcels' => $parcels,
            'actions' => $this->actions($parcels),
            'counters' => [
                'carsArriving' => count($cars),
                'parcelsSent' => count($parcels),
                'balanceCents' => 0,
            ],
            'freightBilledCents' => $this->freightBilledCents($cars),
        ];
    }

    /**
     * The cars this buyer won, and where they are.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cars(User $buyer): array
    {
        $lots = Lot::query()
            ->where('highest_bidder_id', $buyer->id)
            ->get();

        if ($lots->isEmpty()) {
            return [];
        }

        $shipmentIds = ShipmentLot::query()
            ->whereIn('lot_id', $lots->pluck('id'))
            ->pluck('shipment_id');

        $shipments = Shipment::query()
            ->with('milestones')
            ->whereIn('id', $shipmentIds)
            ->get()
            ->keyBy('id');

        $cleared = ClearanceTask::query()
            ->whereIn('shipment_id', $shipmentIds)
            ->where('status', 'complete')
            ->pluck('shipment_id')
            ->unique();

        return $lots->map(function (Lot $lot) use ($shipments, $cleared): array {
            $shipmentLot = ShipmentLot::query()->where('lot_id', $lot->id)->first();
            $shipment = $shipmentLot ? $shipments->get($shipmentLot->shipment_id) : null;

            return [
                'title' => $lot->title,
                'amountCents' => (int) $lot->current_bid,
                'shipment_id' => $shipment?->id,
                'container_no' => $shipment?->container_no,
                'vessel' => $shipment?->vessel,
                'origin' => $shipment?->seaport_origin,
                'destination' => $shipment?->seaport_destination,
                'eta' => $shipment?->eta,
                'status' => $shipment?->status,
                'cleared' => $shipment ? $cleared->contains($shipment->id) : false,
                'milestones' => $shipment
                    ? $shipment->milestones->sortByDesc('occurred_at')->values()
                    : collect(),
            ];
        })->all();
    }

    /**
     * @return array<int, ParcelBooking>
     */
    private function parcels(User $buyer): array
    {
        return ParcelBooking::query()
            ->where(function ($query) use ($buyer): void {
                $query->where('user_id', $buyer->id);

                if (filled($buyer->email)) {
                    $query->orWhere('email', $buyer->email);
                }
            })
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * What the buyer has to do. Only what the database can prove: a parcel
     * waiting for payment. The reference design also shows an ID upload and a
     * collection address, and neither has a data model yet.
     *
     * @param  array<int, ParcelBooking>  $parcels
     * @return array<int, array{title: string, detail: string, action: string, tag: string, tone: string}>
     */
    private function actions(array $parcels): array
    {
        $actions = [];

        foreach ($parcels as $parcel) {
            if ($parcel->status === ParcelBookingStatus::Booked) {
                continue;
            }

            $expired = $parcel->expires_at !== null && $parcel->expires_at->isPast();
            $money = '$'.number_format(((int) $parcel->quoted_total_cents) / 100, 2);

            $actions[] = [
                'title' => $expired
                    ? 'Your parcel hold expired, so the price has moved'
                    : sprintf('Pay %s for the parcel you sent', $money),
                'detail' => sprintf(
                    '%d g from %s · %s',
                    $parcel->weight_grams,
                    $parcel->postcode,
                    $expired
                        ? 'get a new price and we will book it again'
                        : 'held until '.($parcel->expires_at?->toFormattedDateString() ?? 'payment'),
                ),
                'action' => $expired ? 'Get a new price' : 'Pay and book',
                'tag' => $expired ? 'expired' : 'unpaid',
                'tone' => $expired ? 'warn' : 'bad',
            ];
        }

        return $actions;
    }

    /**
     * Duty, VAT and port charges paid on the buyer's containers. The freight
     * billed to Tema is the sum of the receipts, not a number typed in.
     *
     * @param  array<int, array<string, mixed>>  $cars
     */
    private function freightBilledCents(array $cars): int
    {
        $shipmentIds = collect($cars)->pluck('shipment_id')->filter()->unique();

        if ($shipmentIds->isEmpty()) {
            return 0;
        }

        return (int) ClearanceTask::query()
            ->whereIn('shipment_id', $shipmentIds)
            ->where('status', 'complete')
            ->sum('cost');
    }
}
