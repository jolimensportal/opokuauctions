<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ClearanceTask;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Tema customs clearance.
 *
 * A checklist, but not a decorative one. Two things make it matter:
 *
 * 1. **Money tasks post their cost.** Duty, VAT and port charges are the
 *    difference between what a buyer paid and what the platform actually spent.
 *    Without them the landed-cost promise is a guess.
 *
 * 2. **A tick without a receipt is unevidenced, not complete.** Customs
 *    disputes are settled with documents. A completed money task with no
 *    receipt attached is surfaced as a problem rather than quietly passing.
 */
class ClearanceService
{
    /**
     * The template, in the order they actually happen.
     *
     * `cost` marks the tasks that move money, and therefore must be evidenced.
     * Gate-out is last because it is the moment the buyer can collect.
     */
    public const TEMPLATE = [
        ['type' => 'BL_RECEIVED', 'label' => 'Bill of lading received from forwarder', 'cost' => false],
        ['type' => 'IMPORT_DECLARATION', 'label' => 'Import declaration filed (GCNet)', 'cost' => false],
        ['type' => 'PERMIT_CLEARED', 'label' => 'GHAPHA / FDA import permit cleared', 'cost' => false],
        ['type' => 'DUTY_PAID', 'label' => 'Duty assessed and paid', 'cost' => true],
        ['type' => 'VAT_PAID', 'label' => 'VAT paid', 'cost' => true],
        ['type' => 'PORT_CHARGES', 'label' => 'Port charges and handling settled', 'cost' => true],
        ['type' => 'RELEASE_ORDER', 'label' => 'Examination / release order obtained', 'cost' => false],
        ['type' => 'GATE_OUT_PERMIT', 'label' => 'Gate-out permit collected', 'cost' => false],
        ['type' => 'GATE_OUT', 'label' => 'Gate-out executed at Tema port', 'cost' => false],
    ];

    /** Task types that move money, derived from the template. */
    public static function moneyTypes(): array
    {
        return array_column(
            array_filter(self::TEMPLATE, fn ($t) => $t['cost']),
            'type'
        );
    }

    public static function labelFor(string $type): string
    {
        foreach (self::TEMPLATE as $task) {
            if ($task['type'] === $type) {
                return $task['label'];
            }
        }

        return $type;
    }

    public static function costsMoney(string $type): bool
    {
        return in_array($type, self::moneyTypes(), true);
    }

    /**
     * Generate the checklist for a shipment.
     *
     * Idempotent: re-running adds nothing, so a template can be regenerated
     * safely after a partial failure.
     */
    public function createTemplate(string $shipmentId): array
    {
        if (! Shipment::where('id', $shipmentId)->exists()) {
            throw new RuntimeException("Shipment {$shipmentId} not found");
        }

        $created = [];

        foreach (self::TEMPLATE as $index => $task) {
            $created[] = ClearanceTask::firstOrCreate(
                ['shipment_id' => $shipmentId, 'type' => $task['type']],
                [
                    'id' => 'clt_'.Str::random(16),
                    'position' => $index,
                    'status' => 'pending',
                ],
            );
        }

        return $created;
    }

    /**
     * Complete a task.
     *
     * @param  int|null  $cost  minor units of USD; required for money tasks
     * @param  string|null  $receiptPath  required for money tasks
     */
    public function completeTask(
        string $taskId,
        User $actor,
        ?int $cost = null,
        ?string $receiptPath = null,
        ?string $notes = null
    ): ClearanceTask {
        $task = ClearanceTask::where('id', $taskId)->lockForUpdate()->first();

        if (! $task) {
            throw new RuntimeException("Clearance task {$taskId} not found");
        }

        if (! $actor->role->canOperate()) {
            throw new RuntimeException('Only operations staff can complete clearance tasks');
        }

        $costsMoney = self::costsMoney($task->type);

        // A money task without a figure is not a completed money task.
        if ($costsMoney && ($cost === null || $cost <= 0)) {
            throw new RuntimeException(
                'A '.self::labelFor($task->type).' task must record the amount paid'
            );
        }

        $task->update([
            'status' => 'complete',
            'cost' => $cost,
            'receipt_path' => $receiptPath,
            'notes' => $notes,
            'completed_by_user_id' => $actor->id,
            'completed_at' => now(),
        ]);

        return $task;
    }

    /**
     * Money tasks that are complete but carry no receipt.
     *
     * Surfaced rather than tolerated: this is the list that becomes a customs
     * problem three weeks later.
     */
    public function unevidencedMoneyTasks(string $shipmentId): array
    {
        return ClearanceTask::where('shipment_id', $shipmentId)
            ->whereIn('type', self::moneyTypes())
            ->where('status', 'complete')
            ->where(function ($query) {
                $query->whereNull('receipt_path')->orWhere('receipt_path', '');
            })
            ->get()
            ->all();
    }

    public function isFullyCleared(string $shipmentId): bool
    {
        $total = ClearanceTask::where('shipment_id', $shipmentId)->count();
        $done = ClearanceTask::where('shipment_id', $shipmentId)
            ->where('status', 'complete')
            ->count();

        return $total > 0 && $total === $done;
    }

    /**
     * The landed cost of a shipment: everything the platform spent to get the
     * vehicles to Tema.
     *
     * freight + insurance + every recorded clearance cost.
     */
    public function landedCost(string $shipmentId): int
    {
        $shipment = Shipment::where('id', $shipmentId)->first();

        if (! $shipment) {
            throw new RuntimeException("Shipment {$shipmentId} not found");
        }

        $clearance = (int) ClearanceTask::where('shipment_id', $shipmentId)
            ->whereNotNull('cost')
            ->sum('cost');

        return (int) $shipment->freight_cost
            + (int) $shipment->insurance_cost
            + $clearance;
    }

    /**
     * Landed cost per vehicle.
     *
     * Divided by the number of lots aboard. This is the figure quoted to a
     * buyer before they bid, and the figure the platform's margin is measured
     * against, so the division has to be explicit rather than implied.
     */
    public function landedCostPerLot(string $shipmentId): int
    {
        $shipment = Shipment::where('id', $shipmentId)->first();

        if (! $shipment) {
            throw new RuntimeException("Shipment {$shipmentId} not found");
        }

        $lots = ShipmentLot::where('shipment_id', $shipmentId)->sum('quantity');

        if ($lots <= 0) {
            throw new RuntimeException(
                'Cannot divide landed cost: nothing is allocated to this shipment yet'
            );
        }

        return intdiv($this->landedCost($shipmentId), (int) $lots);
    }

    /**
     * Per-lot landed cost: what the buyer paid, plus that vehicle's share of
     * what it cost to move it. The sum of these is what the buyer effectively
     * paid all-in.
     */
    public function landedCostBreakdown(string $shipmentId): array
    {
        $shipment = Shipment::where('id', $shipmentId)->first();

        if (! $shipment) {
            throw new RuntimeException("Shipment {$shipmentId} not found");
        }

        $links = ShipmentLot::where('shipment_id', $shipmentId)->with('lot')->get();

        if ($links->isEmpty()) {
            throw new RuntimeException('Nothing is allocated to this shipment yet');
        }

        $share = $this->landedCostPerLot($shipmentId);
        $breakdown = [];

        foreach ($links as $link) {
            $purchase = (int) $link->lot->current_bid;
            $breakdown[] = [
                'lot_id' => $link->lot_id,
                'description' => $link->lot->year.' '.$link->lot->make.' '.$link->lot->model,
                'purchase_price' => $purchase,
                'freight_and_clearance_share' => $share,
                'landed_cost' => $purchase + $share,
            ];
        }

        return $breakdown;
    }
}