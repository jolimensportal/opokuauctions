<?php

namespace App\Services;

use App\Models\Shipment;
use App\Models\ShipmentMilestone;

/**
 * How long a container may go without moving before it is a problem.
 *
 * One rule, in one place. The corridor board had its own copy of this
 * threshold and the logistics console would have had another, and two
 * dashboards that disagree about whether a container is late is worse than
 * either one.
 */
final class ShipmentStaleness
{
    /** A container with no movement for this many days is silent. */
    public const DAYS = 3;

    /**
     * The statuses that mean a container is under way. A container in one of
     * these with no milestone at all is suspicious; a container that has not
     * started yet is not late, it is just booked.
     *
     * @var list<string>
     */
    public const MOVING_STATUSES = ['LOADED', 'DEPARTED', 'SAILING'];

    /**
     * The container's last milestone, with the same tie-break the corridor
     * board uses: two milestones can share an instant, and the one recorded
     * later is the later fact.
     */
    public static function lastMilestone(Shipment $shipment): ?ShipmentMilestone
    {
        return ShipmentMilestone::query()
            ->where('shipment_id', $shipment->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /** Days since the container last moved. Null when it never has. */
    public static function daysSince(Shipment $shipment): ?int
    {
        $last = self::lastMilestone($shipment);

        if ($last === null) {
            return null;
        }

        return (int) $last->occurred_at->diffInDays(now());
    }

    /**
     * True when the container has gone quiet for too long.
     *
     * The milestone may be passed in when the caller has already loaded it,
     * so a screen that lists thirty containers does not ask the database
     * thirty times for the same answer.
     */
    public static function isSilentSince(?ShipmentMilestone $last, string $status): bool
    {
        if ($last === null) {
            return in_array($status, self::MOVING_STATUSES, true);
        }

        return (int) $last->occurred_at->diffInDays(now()) >= self::DAYS;
    }

    public static function isSilent(Shipment $shipment): bool
    {
        return self::isSilentSince(self::lastMilestone($shipment), $shipment->status);
    }
}
