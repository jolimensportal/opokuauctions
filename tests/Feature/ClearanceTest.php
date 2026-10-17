<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ClearanceTask;
use App\Models\Lot;
use App\Models\Shipment;
use App\Models\User;
use App\Services\ClearanceService;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customs clearance, where a checklist turns into real money.
 *
 * The landed-cost assertions matter most: that figure is quoted to buyers
 * before they bid and the platform's margin is measured against it, so it has
 * to be a number derived from evidence rather than an estimate someone typed.
 */
class ClearanceTest extends TestCase
{
    use RefreshDatabase;

    private ClearanceService $clearance;

    private ShipmentService $shipments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearance = app(ClearanceService::class);
        $this->shipments = app(ShipmentService::class);
    }

    private function shipmentWithLots(int $count = 1): string
    {
        $shipmentId = $this->shipments->createShipment([
            'container_no' => 'MSKU'.uniqid(),
            'seaport_destination' => 'Tema',
            'freight_cost' => 900_00,
            'insurance_cost' => 100_00,
        ])->id;

        for ($i = 0; $i < $count; $i++) {
            $lot = Lot::create([
                'id' => 'lot_'.uniqid(),
                'kind' => 'vehicle',
                'provenance' => 'salvage_auction',
                'title' => 'Toyota Camry',
                'city' => 'Detroit',
                'photos' => [],
                'starting_bid' => 100000,
                'reserve_price' => 500000,
                'bid_increment' => 25000,
                'deposit_bps' => 2000,
                'balance_settled_at' => now(),
                'starts_at' => now()->subMinute(),
                'ends_at' => now()->addDay(),
                'status' => 'SOLD',
                'current_bid' => 600000,
                'highest_bidder_id' => (string) User::factory()->create()->id,
                'bid_count' => 4,
            ]);

            $this->shipments->allocateLot($shipmentId, $lot->id);
        }

        return $shipmentId;
    }

    // ------------------------------------------------------------- template

    public function test_the_template_covers_the_whole_clearance(): void
    {
        $shipmentId = $this->shipmentWithLots();

        $tasks = $this->clearance->createTemplate($shipmentId);

        $this->assertCount(9, $tasks);

        $types = ClearanceTask::where('shipment_id', $shipmentId)
            ->orderBy('position')
            ->pluck('type')
            ->all();

        $this->assertSame('BL_RECEIVED', $types[0], 'bill of lading comes first');
        $this->assertSame('GATE_OUT', $types[8], 'gate-out is last; until then the buyer cannot collect');
    }

    public function test_generating_the_template_twice_adds_nothing(): void
    {
        $shipmentId = $this->shipmentWithLots();

        $this->clearance->createTemplate($shipmentId);
        $this->clearance->createTemplate($shipmentId);

        $this->assertSame(9, ClearanceTask::where('shipment_id', $shipmentId)->count());
    }

    // ------------------------------------------------------ money discipline

    public function test_a_money_task_cannot_be_completed_without_an_amount(): void
    {
        $shipmentId = $this->shipmentWithLots();
        $ops = User::factory()->create(['role' => Role::Ops]);
        $this->clearance->createTemplate($shipmentId);

        $duty = ClearanceTask::where('shipment_id', $shipmentId)->where('type', 'DUTY_PAID')->first();

        $this->expectExceptionMessage('must record the amount paid');

        $this->clearance->completeTask($duty->id, $ops);
    }

    public function test_a_money_task_records_its_cost_and_receipt(): void
    {
        $shipmentId = $this->shipmentWithLots();
        $ops = User::factory()->create(['role' => Role::Ops]);
        $this->clearance->createTemplate($shipmentId);

        $duty = ClearanceTask::where('shipment_id', $shipmentId)->where('type', 'DUTY_PAID')->first();

        $done = $this->clearance->completeTask(
            $duty->id,
            $ops,
            250_00,
            'receipts/gcra-duty-2026-08.pdf',
            'Paid at Tema'
        );

        $this->assertSame('complete', $done->status);
        $this->assertSame(250_00, $done->cost);
        $this->assertSame('receipts/gcra-duty-2026-08.pdf', $done->receipt_path);
        $this->assertNotNull($done->completed_at);
        $this->assertSame($ops->id, $done->completed_by_user_id);
    }

    public function test_a_non_money_task_needs_no_amount(): void
    {
        $shipmentId = $this->shipmentWithLots();
        $ops = User::factory()->create(['role' => Role::Ops]);
        $this->clearance->createTemplate($shipmentId);

        $bl = ClearanceTask::where('shipment_id', $shipmentId)->where('type', 'BL_RECEIVED')->first();

        $done = $this->clearance->completeTask($bl->id, $ops);

        $this->assertSame('complete', $done->status);
        $this->assertNull($done->cost);
    }

    public function test_a_buyer_cannot_complete_clearance_tasks(): void
    {
        $shipmentId = $this->shipmentWithLots();
        $buyer = User::factory()->create(['role' => Role::Buyer]);
        $this->clearance->createTemplate($shipmentId);

        $bl = ClearanceTask::where('shipment_id', $shipmentId)->where('type', 'BL_RECEIVED')->first();

        $this->expectExceptionMessage('Only operations staff');

        $this->clearance->completeTask($bl->id, $buyer);
    }

    // ----------------------------------------------------------- unevidenced

    public function test_completed_money_tasks_without_receipts_are_surfaced(): void
    {
        $shipmentId = $this->shipmentWithLots();
        $ops = User::factory()->create(['role' => Role::Ops]);
        $this->clearance->createTemplate($shipmentId);

        $duty = ClearanceTask::where('shipment_id', $shipmentId)->where('type', 'DUTY_PAID')->first();
        $this->clearance->completeTask($duty->id, $ops, 250_00); // no receipt

        $unevidenced = $this->clearance->unevidencedMoneyTasks($shipmentId);

        $this->assertCount(1, $unevidenced);
        $this->assertSame('DUTY_PAID', $unevidenced[0]->type);
        $this->assertTrue($unevidenced[0]->isUnevidenced());
    }

    public function test_a_receipt_clears_the_unevidenced_flag(): void
    {
        $shipmentId = $this->shipmentWithLots();
        $ops = User::factory()->create(['role' => Role::Ops]);
        $this->clearance->createTemplate($shipmentId);

        $vat = ClearanceTask::where('shipment_id', $shipmentId)->where('type', 'VAT_PAID')->first();
        $this->clearance->completeTask($vat->id, $ops, 180_00, 'receipts/vat.pdf');

        $this->assertCount(0, $this->clearance->unevidencedMoneyTasks($shipmentId));
    }

    // --------------------------------------------------------- landed cost

    public function test_landed_cost_sums_freight_insurance_and_clearance(): void
    {
        $shipmentId = $this->shipmentWithLots();
        $ops = User::factory()->create(['role' => Role::Ops]);
        $this->clearance->createTemplate($shipmentId);

        // freight 900.00 + insurance 100.00 already set on the shipment
        $this->clearance->completeTask(
            ClearanceTask::where('shipment_id', $shipmentId)->where('type', 'DUTY_PAID')->first()->id,
            $ops, 250_00, 'r.pdf'
        );
        $this->clearance->completeTask(
            ClearanceTask::where('shipment_id', $shipmentId)->where('type', 'VAT_PAID')->first()->id,
            $ops, 180_00, 'r.pdf'
        );
        $this->clearance->completeTask(
            ClearanceTask::where('shipment_id', $shipmentId)->where('type', 'PORT_CHARGES')->first()->id,
            $ops, 70_00, 'r.pdf'
        );

        // 900 + 100 + 250 + 180 + 70
        $this->assertSame(1500_00, $this->clearance->landedCost($shipmentId));
    }

    public function test_landed_cost_is_divided_across_the_vehicles_aboard(): void
    {
        $shipmentId = $this->shipmentWithLots(count: 3);

        // 900 + 100 freight/insurance, no clearance costs recorded yet
        $this->assertSame(1000_00, $this->clearance->landedCost($shipmentId));
        $this->assertSame(33333, $this->clearance->landedCostPerLot($shipmentId));
    }

    public function test_landed_cost_cannot_be_divided_with_nothing_aboard(): void
    {
        $shipmentId = $this->shipments->createShipment([
            'container_no' => 'MSKU'.uniqid(),
            'freight_cost' => 500_00,
        ])->id;

        $this->expectExceptionMessage('nothing is allocated');

        $this->clearance->landedCostPerLot($shipmentId);
    }

    public function test_the_breakdown_gives_each_vehicle_its_own_landed_cost(): void
    {
        $shipmentId = $this->shipmentWithLots(count: 2);

        $breakdown = $this->clearance->landedCostBreakdown($shipmentId);

        $this->assertCount(2, $breakdown);

        foreach ($breakdown as $row) {
            // each bought at 6000.00, plus half of 1000.00 in costs
            $this->assertSame(6000_00, $row['purchase_price']);
            $this->assertSame(500_00, $row['freight_and_clearance_share']);
            $this->assertSame(6500_00, $row['landed_cost']);
        }
    }

    public function test_full_clearance_is_reported_only_when_everything_is_done(): void
    {
        $shipmentId = $this->shipmentWithLots();
        $ops = User::factory()->create(['role' => Role::Ops]);
        $tasks = $this->clearance->createTemplate($shipmentId);

        $this->assertFalse($this->clearance->isFullyCleared($shipmentId));

        foreach ($tasks as $task) {
            $this->clearance->completeTask(
                $task->id,
                $ops,
                ClearanceService::costsMoney($task->type) ? 100_00 : null,
                ClearanceService::costsMoney($task->type) ? 'r.pdf' : null,
            );
        }

        $this->assertTrue($this->clearance->isFullyCleared($shipmentId));
    }
}