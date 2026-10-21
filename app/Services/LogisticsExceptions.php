<?php

namespace App\Services;

use App\Models\ParcelBooking;
use App\Models\Shipment;
use Carbon\Carbon;

/**
 * The queue of things that are stopped.
 *
 * Each item names what is wrong, what it refers to, and the next action. An
 * item without a next action is a worry, not a task, and the console has
 * nowhere to put a worry.
 */
class LogisticsExceptions
{
    /**
     * @return array<int, array{kind: string, title: string, detail: string, action: string, tag: string, tone: string, reference: ?string}>
     */
    public function all(): array
    {
        return [
            ...$this->silentContainers(),
            ...$this->missingCarriers(),
            ...$this->expiredHolds(),
        ];
    }

    /**
     * Containers that claim to be moving and have not moved.
     *
     * @return array<int, array{kind: string, title: string, detail: string, action: string, tag: string, tone: string, reference: ?string}>
     */
    private function silentContainers(): array
    {
        return Shipment::query()
            ->get()
            ->filter(fn (Shipment $shipment): bool => ShipmentStaleness::isSilent($shipment))
            ->map(function (Shipment $shipment): array {
                $days = ShipmentStaleness::daysSince($shipment);

                return [
                    'kind' => 'silent_container',
                    'title' => sprintf(
                        '%s has not moved in %s',
                        $shipment->container_no,
                        $days === null ? 'its whole life' : $days.' days',
                    ),
                    'detail' => sprintf(
                        '%s · %s → %s · status %s',
                        $shipment->vessel ?? 'No vessel recorded',
                        $shipment->seaport_origin ?? '?',
                        $shipment->seaport_destination ?? '?',
                        $shipment->status,
                    ),
                    'action' => 'Record a milestone, or check the tracking feed.',
                    'tag' => $days === null ? 'never moved' : $days.' days',
                    'tone' => 'bad',
                    'reference' => $shipment->container_no,
                ];
            })
            ->all();
    }

    /**
     * Containers that cannot be booked or costed because no carrier is on
     * file. The corridor board calls this "needs booking" and counts a
     * container with no number the same way.
     *
     * @return array<int, array{kind: string, title: string, detail: string, action: string, tag: string, tone: string, reference: ?string}>
     */
    private function missingCarriers(): array
    {
        return Shipment::query()
            ->where(fn ($query) => $query->whereNull('forwarder_id')->orWhereNull('container_no'))
            ->get()
            ->map(function (Shipment $shipment): array {
                $missing = $shipment->forwarder_id === null
                    ? 'no forwarder on file'
                    : 'no container number on file';

                return [
                    'kind' => 'missing_forwarder',
                    'title' => sprintf('%s has %s', $shipment->container_no, $missing),
                    'detail' => 'Nothing can be booked, costed or claimed against a carrier until it is fixed.',
                    'action' => 'Create the forwarder, then assign it to the container.',
                    'tag' => 'blocking',
                    'tone' => 'warn',
                    'reference' => $shipment->container_no,
                ];
            })
            ->all();
    }

    /**
     * Customer parcels whose price hold expired before anyone paid.
     *
     * @return array<int, array{kind: string, title: string, detail: string, action: string, tag: string, tone: string, reference: ?string}>
     */
    private function expiredHolds(): array
    {
        return ParcelBooking::query()
            ->where('status', 'payment_pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get()
            ->map(fn (ParcelBooking $booking): array => [
                'kind' => 'expired_hold',
                'title' => 'A parcel hold expired while it was still unpaid',
                'detail' => sprintf(
                    '%d g from %s · quoted $%s · hold expired %s',
                    $booking->weight_grams,
                    $booking->postcode,
                    number_format(((int) $booking->quoted_total_cents) / 100, 2),
                    Carbon::parse($booking->expires_at)->toFormattedDateString(),
                ),
                'action' => 'Chase the payment or release the hold.',
                'tag' => 'expired',
                'tone' => 'bad',
                'reference' => $booking->reference,
            ])
            ->all();
    }
}
