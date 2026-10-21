<?php

namespace App\Services;

use App\Models\Shipment;
use App\Models\ShipmentMilestone;
use App\Models\User;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes a milestone and moves the container with it.
 *
 * The two are written in one call and in one transaction, because they are
 * one fact: the container is where the milestone says it is. A milestone
 * recorded without moving the container is how a container ends up looking
 * booked while it is at sea.
 *
 * The source column matters. An operator's record is never overwritten by a
 * later feed poll — the operator was there.
 */
class ShipmentMilestoneRecorder
{
    public function record(
        Shipment $shipment,
        string $type,
        DateTimeInterface|string|null $occurredAt = null,
        ?string $notes = null,
        ?User $recordedBy = null,
        string $source = 'admin',
    ): ShipmentMilestone {
        // A form hands back a string; code hands back a date. Both are the
        // same fact and neither is worth making the caller convert.
        $occurredAt = $occurredAt === null
            ? now()
            : ($occurredAt instanceof DateTimeInterface ? $occurredAt : Carbon::parse($occurredAt));

        return DB::transaction(function () use ($shipment, $type, $occurredAt, $notes, $recordedBy, $source): ShipmentMilestone {
            $milestone = ShipmentMilestone::create([
                'id' => 'ms_'.Str::lower(Str::random(12)),
                'shipment_id' => $shipment->id,
                'type' => $type,
                'occurred_at' => $occurredAt,
                'source' => $source,
                'recorded_by_user_id' => $recordedBy?->id,
                'notes' => $notes,
            ]);

            $shipment->forceFill(['status' => $type])->save();

            return $milestone;
        });
    }
}
