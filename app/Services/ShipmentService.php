<?php

namespace App\Services;

use App\Models\Lot;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use App\Models\ShipmentLotStatus;
use App\Models\ShipmentMilestone;
use App\Notifications\CorridorNotifier;
use App\Services\ClearanceService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The corridor: booking containers, allocating won lots, and recording what
 * actually happened to them.
 *
 * Three rules carry the weight here.
 *
 * 1. **Loading is gated on payment.** A container does not load while any lot
 *    aboard still owes its balance. This prevents an entire class of loss —
 *    car loaded, buyer ghosts, you own a depreciating container of vehicles.
 *    The gate reads `lot.balance_settled_at`, which the money service sets when
 *    funds are actually held, so it cannot drift from the ledger.
 *
 * 2. **Admin records win.** Milestones arrive from two places: a tracking API
 *    and a human who was at the port. The human is authoritative and is never
 *    overwritten by a later poll.
 *
 * 3. **A lot rides in exactly one container.** Enforced by a unique index, so
 *    the platform cannot promise the same car to two buyers even under a race.
 */
class ShipmentService
{
    /** Legal forward moves. Anything else is a mistake, not a shortcut. */
    private const TRANSITIONS = [
        'BOOKING' => ['LOADED', 'CANCELLED'],
        'LOADED' => ['SAILING', 'CANCELLED'],
        'SAILING' => ['ARRIVED'],
        'ARRIVED' => ['DISCHARGED'],
        'DISCHARGED' => ['GATE_OUT'],
        'GATE_OUT' => ['CLEARED'],
        'CLEARED' => ['DELIVERED', 'DISPUTED'],
        'DELIVERED' => ['DISPUTED'],
        'DISPUTED' => ['DELIVERED'],
        'CANCELLED' => [],
    ];

    /** Shipment state that corresponds to each milestone. */
    private const MILESTONE_STATE = [
        'LOADED' => 'LOADED',
        'SAILED' => 'SAILING',
        'ARRIVED' => 'ARRIVED',
        'DISCHARGED' => 'DISCHARGED',
        'GATE_OUT' => 'GATE_OUT',
        'CLEARED' => 'CLEARED',
        'DELIVERED' => 'DELIVERED',
    ];

    public function createShipment(array $data): Shipment
    {
        $shipment = Shipment::create(array_merge([
            'id' => 'shp_'.Str::random(16),
            'status' => 'BOOKING',
        ], $data));

        // The Tema checklist exists from the moment a container is created, not
        // when it arrives. Nine documents have to be chased over weeks, and
        // discovering the list on arrival is how containers miss sailing dates.
        try {
            app(ClearanceService::class)->createTemplate($shipment->id);
        } catch (\Throwable $e) {
            report($e);
        }

        return $shipment;
    }

    /**
     * Put a won lot on a container.
     *
     * Refuses anything that is not SOLD, and lets the unique index catch a
     * double allocation rather than trusting a read-then-write check.
     */
    public function allocateLot(string $shipmentId, string $lotId): ShipmentLot
    {
        return DB::transaction(function () use ($shipmentId, $lotId) {
            $shipment = Shipment::where('id', $shipmentId)->lockForUpdate()->first();
            if (! $shipment) {
                throw new RuntimeException("Shipment {$shipmentId} not found");
            }

            if (in_array($shipment->status, ['CANCELLED', 'DELIVERED', 'DISPUTED'], true)) {
                throw new RuntimeException(
                    "Cannot allocate to a shipment in state {$shipment->status}"
                );
            }

            $lot = Lot::where('id', $lotId)->lockForUpdate()->first();
            if (! $lot) {
                throw new RuntimeException("Lot {$lotId} not found");
            }

            if ($lot->status !== 'SOLD') {
                throw new RuntimeException(
                    "Only SOLD lots can be shipped; lot {$lotId} is {$lot->status}"
                );
            }

            $link = ShipmentLot::firstOrCreate(
                ['shipment_id' => $shipmentId, 'lot_id' => $lotId],
                ['quantity' => 1],
            );

            ShipmentLotStatus::firstOrCreate(
                ['shipment_lot_id' => $link->id],
                ['status' => 'ALLOCATED'],
            );

            return $link;
        });
    }

    /**
     * Record a milestone and move the shipment forward.
     *
     * @param  string  $source  'admin' or 'api'. An admin record is never
     *                           replaced by a later API poll.
     */
    public function recordMilestone(
        string $shipmentId,
        string $type,
        string $source = 'api',
        ?string $recordedByUserId = null,
        ?string $notes = null
    ): ShipmentMilestone {
        $milestone = DB::transaction(function () use ($shipmentId, $type, $source, $recordedByUserId, $notes) {
            $shipment = Shipment::where('id', $shipmentId)->lockForUpdate()->first();
            if (! $shipment) {
                throw new RuntimeException("Shipment {$shipmentId} not found");
            }

            // Rule 2: a human record stands. An API poll may only fill a gap.
            $existing = ShipmentMilestone::where('shipment_id', $shipmentId)
                ->where('type', $type)
                ->orderByDesc('created_at')
                ->first();

            if ($existing && $existing->source === 'admin') {
                return $existing;
            }

            // Rule 1: the gate.
            if ($type === 'LOADED') {
                $this->assertFullyPaid($shipmentId);
            }

            $target = self::MILESTONE_STATE[$type] ?? null;
            if ($target !== null) {
                $this->assertTransition($shipment->status, $target);
            }

            $milestone = ShipmentMilestone::updateOrCreate(
                ['shipment_id' => $shipmentId, 'type' => $type],
                [
                    'id' => $existing->id ?? 'ms_'.Str::random(16),
                    'occurred_at' => now(),
                    'source' => $source,
                    'recorded_by_user_id' => $recordedByUserId,
                    'notes' => $notes,
                ],
            );

            if ($target !== null) {
                $shipment->status = $target;
                if ($type === 'SAILED') {
                    $shipment->actual_departure = now();
                }
                if ($type === 'ARRIVED') {
                    $shipment->actual_arrival = now();
                }
                $shipment->save();
            }

            $this->propagateLotStatus($shipmentId, $target);

            return $milestone;
        });

        // Deliberately AFTER the transaction commits. Telling a buyer about a
        // milestone must never be able to roll back the milestone itself: the
        // container moved whether or not WhatsApp is reachable.
        try {
            app(CorridorNotifier::class)->notifyShipmentMilestone($milestone);
        } catch (\Throwable $e) {
            report($e);
        }

        return $milestone;
    }

    /**
     * The gate. A container does not load while any lot aboard still owes its
     * balance.
     */
    private function assertFullyPaid(string $shipmentId): void
    {
        $unpaid = DB::table('shipment_lots')
            ->join('lot', 'lot.id', '=', 'shipment_lots.lot_id')
            ->where('shipment_lots.shipment_id', $shipmentId)
            ->whereNull('lot.balance_settled_at')
            ->select('lot.id', 'lot.make', 'lot.model', 'lot.year')
            ->get();

        if ($unpaid->isNotEmpty()) {
            $names = $unpaid->map(fn ($l) => "{$l->year} {$l->make} {$l->model}")->implode(', ');

            throw new RuntimeException(
                "Cannot load: {$unpaid->count()} lot(s) still owe their balance — {$names}."
            );
        }
    }

    private function assertTransition(string $from, string $to): void
    {
        $allowed = self::TRANSITIONS[$from] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw new RuntimeException(
                "Illegal shipment transition {$from} -> {$to}. Allowed: ".
                ($allowed ? implode(', ', $allowed) : 'none, this is a terminal state')
            );
        }
    }

    /**
     * Push the shipment state down to each lot, unless that lot has been
     * singled out for an exception. One car held at customs must not stall the
     * other four in the same container.
     */
    private function propagateLotStatus(string $shipmentId, ?string $state): void
    {
        if ($state === null) {
            return;
        }

        $lotState = match ($state) {
            'LOADED' => 'LOADED',
            'SAILING', 'ARRIVED' => 'IN_TRANSIT',
            'DISCHARGED', 'GATE_OUT' => 'CLEARED',
            'DELIVERED' => 'DELIVERED',
            default => null,
        };

        if ($lotState === null) {
            return;
        }

        $links = ShipmentLot::where('shipment_id', $shipmentId)->get();

        foreach ($links as $link) {
            $status = ShipmentLotStatus::where('shipment_lot_id', $link->id)->first();

            // HELD, DAMAGED and WRITTEN_OFF are deliberate exceptions. Bulk
            // progression must not paper over one.
            if ($status && in_array($status->status, ['HELD', 'DAMAGED', 'WRITTEN_OFF'], true)) {
                continue;
            }

            ShipmentLotStatus::updateOrCreate(
                ['shipment_lot_id' => $link->id],
                ['status' => $lotState],
            );
        }
    }

    /**
     * Flag one lot inside a container as an exception, leaving its siblings
     * alone. This is what makes partial delivery possible.
     */
    public function flagLot(string $shipmentId, string $lotId, string $status, ?string $note = null): ShipmentLotStatus
    {
        $link = ShipmentLot::where('shipment_id', $shipmentId)
            ->where('lot_id', $lotId)
            ->first();

        if (! $link) {
            throw new RuntimeException("Lot {$lotId} is not on shipment {$shipmentId}");
        }

        return ShipmentLotStatus::updateOrCreate(
            ['shipment_lot_id' => $link->id],
            [
                'status' => $status,
                'note' => $note,
                'resolved_at' => in_array($status, ['DELIVERED', 'WRITTEN_OFF'], true) ? now() : null,
            ],
        );
    }
}