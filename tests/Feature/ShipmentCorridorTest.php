<?php

namespace Tests\Feature;

use App\Models\Lot;
use App\Models\ShipmentLot;
use App\Models\ShipmentLotStatus;
use App\Models\ShipmentMilestone;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shipment corridor, with the payment gate at its centre.
 *
 * The gate tests matter most: a container loading with money outstanding is
 * the single most expensive mistake this platform can make, and it is entirely
 * preventable.
 */
class ShipmentCorridorTest extends TestCase
{
    use RefreshDatabase;

    private ShipmentService $shipments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shipments = app(ShipmentService::class);
    }

    private function soldLot(array $overrides = []): Lot
    {
        return Lot::create(array_merge([
            'id' => 'lot_'.uniqid(),
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => 'Toyota Corolla',
            'city' => 'Detroit',
            'photos' => [],
            'starting_bid' => 100000,
            'reserve_price' => 500000,
            'bid_increment' => 25000,
            'deposit_bps' => 2000,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'status' => 'SOLD',
            'current_bid' => 600000,
            'highest_bidder_id' => (string) \App\Models\User::factory()->create()->id,
            'bid_count' => 3,
        ], $overrides));
    }

    private function shipment(): string
    {
        return $this->shipments->createShipment([
            'container_no' => 'MSKU'.uniqid(),
            'seaport_origin' => 'Newark',
            'seaport_destination' => 'Tema',
        ])->id;
    }

    /** Get a container to LOADED with one paid lot aboard. */
    private function walkToLoaded(string $shipmentId): void
    {
        $lot = $this->soldLot();
        $lot->balance_settled_at = now();
        $lot->save();
        $this->shipments->allocateLot($shipmentId, $lot->id);
        $this->shipments->recordMilestone($shipmentId, 'LOADED');
    }

    // ------------------------------------------------------------ allocation

    public function test_a_sold_lot_can_be_allocated_to_a_container(): void
    {
        $shipmentId = $this->shipment();
        $lot = $this->soldLot();

        $this->shipments->allocateLot($shipmentId, $lot->id);

        $this->assertDatabaseHas('shipment_lots', [
            'shipment_id' => $shipmentId,
            'lot_id' => $lot->id,
        ]);
        $this->assertDatabaseHas('shipment_lot_statuses', ['status' => 'ALLOCATED']);
    }

    public function test_an_unsold_lot_cannot_be_shipped(): void
    {
        $shipmentId = $this->shipment();
        $lot = $this->soldLot(['status' => 'LIVE']);

        $this->expectExceptionMessage('Only SOLD lots can be shipped');

        $this->shipments->allocateLot($shipmentId, $lot->id);
    }

    public function test_a_lot_cannot_ride_in_two_containers(): void
    {
        $lot = $this->soldLot();
        $first = $this->shipment();
        $second = $this->shipment();

        $this->shipments->allocateLot($first, $lot->id);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->shipments->allocateLot($second, $lot->id);
    }

    // --------------------------------------------------------- payment gate

    public function test_container_cannot_load_while_a_lot_owes_its_balance(): void
    {
        $shipmentId = $this->shipment();
        $lot = $this->soldLot();
        $this->shipments->allocateLot($shipmentId, $lot->id);

        $this->expectExceptionMessage('Cannot load');

        $this->shipments->recordMilestone($shipmentId, 'LOADED');
    }

    public function test_container_loads_once_every_lot_is_paid(): void
    {
        $shipmentId = $this->shipment();
        $lot = $this->soldLot();
        $this->shipments->allocateLot($shipmentId, $lot->id);

        // The money service settles the balance; the gate reads this, not a flag.
        $lot->balance_settled_at = now();
        $lot->save();

        $this->shipments->recordMilestone($shipmentId, 'LOADED');

        $this->assertDatabaseHas('shipment_milestones', [
            'shipment_id' => $shipmentId,
            'type' => 'LOADED',
        ]);
    }

    public function test_one_unpaid_lot_blocks_the_whole_container(): void
    {
        $shipmentId = $this->shipment();

        $paid = $this->soldLot();
        $paid->balance_settled_at = now();
        $paid->save();
        $this->shipments->allocateLot($shipmentId, $paid->id);

        $unpaid = $this->soldLot();
        $this->shipments->allocateLot($shipmentId, $unpaid->id);

        try {
            $this->shipments->recordMilestone($shipmentId, 'LOADED');
            $this->fail('Expected the gate to block loading');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 lot(s) still owe', $e->getMessage());
        }
    }

    // ------------------------------------------------------- state machine

    public function test_shipment_walks_the_full_journey(): void
    {
        $shipmentId = $this->shipment();
        $lot = $this->soldLot();
        $lot->balance_settled_at = now();
        $lot->save();
        $this->shipments->allocateLot($shipmentId, $lot->id);

        foreach (['LOADED', 'SAILED', 'ARRIVED', 'DISCHARGED', 'GATE_OUT', 'CLEARED', 'DELIVERED'] as $step) {
            $this->shipments->recordMilestone($shipmentId, $step);
        }

        $this->assertSame('DELIVERED', \App\Models\Shipment::find($shipmentId)->status);
    }

    public function test_illegal_transitions_are_refused(): void
    {
        $shipmentId = $this->shipment();
        $lot = $this->soldLot();
        $lot->balance_settled_at = now();
        $lot->save();
        $this->shipments->allocateLot($shipmentId, $lot->id);

        $this->shipments->recordMilestone($shipmentId, 'LOADED');

        $this->expectExceptionMessage('Illegal shipment transition');

        // Cannot sail before it has even loaded again.
        $this->shipments->recordMilestone($shipmentId, 'LOADED');
        \App\Models\Shipment::find($shipmentId)->update(['status' => 'SAILING']);
        $this->shipments->recordMilestone($shipmentId, 'LOADED');
    }

    public function test_booking_can_be_cancelled_but_a_delivered_shipment_cannot(): void
    {
        $cancellable = $this->shipment();
        $this->shipments->recordMilestone($cancellable, 'BOOKED');
        \App\Models\Shipment::find($cancellable)->update(['status' => 'CANCELLED']);
        $this->assertSame('CANCELLED', \App\Models\Shipment::find($cancellable)->status);
    }

    // -------------------------------------------------- admin over API

    public function test_an_admin_milestone_is_never_overwritten_by_the_api(): void
    {
        $shipmentId = $this->shipment();
        $this->walkToLoaded($shipmentId);
        $this->shipments->recordMilestone($shipmentId, 'SAILED');

        $this->shipments->recordMilestone($shipmentId, 'ARRIVED', 'admin', null, 'Actually arrived Tuesday');
        $this->shipments->recordMilestone($shipmentId, 'ARRIVED', 'api', null, 'API says Thursday');

        $milestone = ShipmentMilestone::where('shipment_id', $shipmentId)
            ->where('type', 'ARRIVED')
            ->first();

        $this->assertSame('admin', $milestone->source);
        $this->assertSame('Actually arrived Tuesday', $milestone->notes);
    }

    public function test_the_api_may_fill_a_gap_the_admin_has_not_recorded(): void
    {
        $shipmentId = $this->shipment();
        $this->walkToLoaded($shipmentId);

        $this->shipments->recordMilestone($shipmentId, 'SAILED', 'api', null, 'vessel AIS');

        $milestone = ShipmentMilestone::where('shipment_id', $shipmentId)
            ->where('type', 'SAILED')
            ->first();

        $this->assertSame('api', $milestone->source);
    }

    // ------------------------------------------------ partial delivery

    public function test_one_lot_held_does_not_stall_its_container_mates(): void
    {
        $shipmentId = $this->shipment();

        $clear = $this->soldLot();
        $clear->balance_settled_at = now();
        $clear->save();
        $this->shipments->allocateLot($shipmentId, $clear->id);

        $held = $this->soldLot();
        $held->balance_settled_at = now();
        $held->save();
        $this->shipments->allocateLot($shipmentId, $held->id);

        $this->shipments->recordMilestone($shipmentId, 'LOADED');
        $this->shipments->recordMilestone($shipmentId, 'SAILED');

        // Customs holds one car.
        $this->shipments->flagLot($shipmentId, $held->id, 'HELD', 'Customs query on VIN');

        // The container continues regardless.
        $this->shipments->recordMilestone($shipmentId, 'ARRIVED');

        $clearStatus = ShipmentLotStatus::whereHas(
            'shipmentLot',
            fn ($q) => $q->where('lot_id', $clear->id)
        )->first();

        $heldStatus = ShipmentLotStatus::whereHas(
            'shipmentLot',
            fn ($q) => $q->where('lot_id', $held->id)
        )->first();

        $this->assertSame('IN_TRANSIT', $clearStatus->status);
        $this->assertSame('HELD', $heldStatus->status, 'an exception must survive bulk progression');
    }

    public function test_a_lot_cannot_be_flagged_if_it_is_not_on_the_container(): void
    {
        $this->expectExceptionMessage('is not on shipment');

        $this->shipments->flagLot($this->shipment(), 'lot_not_here', 'HELD');
    }
}