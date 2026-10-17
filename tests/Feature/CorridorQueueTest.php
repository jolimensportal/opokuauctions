<?php

namespace Tests\Feature;

use App\Models\Lot;
use App\Models\Shipment;
use App\Models\ShipmentMilestone;
use App\Models\Wallet;
use App\Services\CorridorQueries;
use App\Services\ShipmentService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seven queues are the console. If they return the wrong rows, the operator
 * is looking at the wrong work, so the queries are tested directly rather than
 * through the UI.
 */
class CorridorQueueTest extends TestCase
{
    use RefreshDatabase;

    private CorridorQueries $q;

    private ShipmentService $shipments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->q = app(CorridorQueries::class);
        $this->shipments = app(ShipmentService::class);
    }

    private function lot(array $overrides = []): Lot
    {
        return Lot::create(array_merge([
            'id' => 'lot_'.uniqid(),
            'vin' => 'VIN-'.uniqid(),
            'year' => 2019,
            'make' => 'Toyota',
            'model' => 'Camry',
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
            'bid_count' => 4,
        ], $overrides));
    }

    private function paidShipment(?string $lotId = null): string
    {
        $shipmentId = $this->shipments->createShipment([
            'container_no' => 'MSKU'.uniqid(),
            'forwarder_id' => null,
            'seaport_destination' => 'Tema',
        ])->id;

        $lot = $this->lot(['balance_settled_at' => now()]);
        $this->shipments->allocateLot($shipmentId, $lot ? $lot->id : $lotId);

        return $shipmentId;
    }

    // ------------------------------------------------------- needs money

    public function test_needs_money_lists_sold_unpaid_lots(): void
    {
        $unpaid = $this->lot();
        $this->lot(['balance_settled_at' => now()]);

        $result = $this->q->needsMoney();

        $this->assertSame(1, $result['count']);
        $this->assertSame($unpaid->id, $result['rows'][0]->id);
    }

    public function test_needs_money_ignores_unsold_lots(): void
    {
        $this->lot(['status' => 'LIVE']);
        $this->lot(['status' => 'UPCOMING']);

        $this->assertSame(0, $this->q->needsMoney()['count']);
    }

    // ------------------------------------------------------ unallocated

    public function test_unallocated_lists_paid_lots_with_no_container(): void
    {
        $orphan = $this->lot(['balance_settled_at' => now()]);
        $this->paidShipment();

        $result = $this->q->unallocated();

        $this->assertSame(1, $result['count']);
        $this->assertSame($orphan->id, $result['rows'][0]->id);
    }

    public function test_an_unpaid_unallocated_lot_is_not_in_both_queues(): void
    {
        // Money not yet taken means it is still in Needs-money, not Unallocated.
        $this->lot();

        $this->assertSame(1, $this->q->needsMoney()['count']);
        $this->assertSame(0, $this->q->unallocated()['count']);
    }

    // ----------------------------------------------------- needs booking

    public function test_needs_booking_flags_missing_forwarder_or_container(): void
    {
        $missingBoth = $this->shipments->createShipment([
            'container_no' => null, 'forwarder_id' => null,
        ])->id;

        $complete = $this->shipments->createShipment([
            'container_no' => 'MSKU'.uniqid(),
            'forwarder_id' => 'fwd_1',
        ])->id;

        $result = $this->q->needsBooking();

        $this->assertSame(1, $result['count']);
        $this->assertSame($missingBoth, $result['rows'][0]->id);
    }

    // -------------------------------------------------------- in transit

    public function test_in_transit_reports_stale_milestones(): void
    {
        $shipmentId = $this->paidShipment();
        $this->shipments->recordMilestone($shipmentId, 'LOADED');
        $this->shipments->recordMilestone($shipmentId, 'SAILED');

        // A genuinely stale container stopped moving days ago. Backdate each
        // milestone to a distinct time so "latest" is unambiguous: loaded six
        // days ago, sailed five, nothing since.
        ShipmentMilestone::where('shipment_id', $shipmentId)->where('type', 'LOADED')
            ->update(['occurred_at' => now()->subDays(6)]);
        ShipmentMilestone::where('shipment_id', $shipmentId)->where('type', 'SAILED')
            ->update(['occurred_at' => now()->subDays(5)]);

        $result = $this->q->inTransit();

        $this->assertSame(1, $result['count']);
        $this->assertSame(1, $result['stale']);
        $this->assertSame('SAILED', $result['rows'][0]->last_milestone);
        $this->assertGreaterThanOrEqual(5, $result['rows'][0]->days_stale);
    }

    public function test_a_fresh_milestone_is_not_stale(): void
    {
        $shipmentId = $this->paidShipment();
        $this->shipments->recordMilestone($shipmentId, 'LOADED');

        $this->assertSame(0, $this->q->inTransit()['stale']);
    }

    // ---------------------------------------------------------- at Tema

    public function test_at_tema_lists_containers_clearing_customs(): void
    {
        $shipmentId = $this->paidShipment();

        foreach (['LOADED', 'SAILED', 'ARRIVED'] as $step) {
            $this->shipments->recordMilestone($shipmentId, $step);
        }

        $this->assertSame(1, $this->q->atTema()['count']);
        $this->assertSame(0, $this->q->inTransit()['count'], 'arrived lots have left in-transit');
    }

    // -------------------------------------------------------- disputed

    public function test_disputed_shipments_are_listed(): void
    {
        $shipmentId = $this->paidShipment();

        foreach (['LOADED', 'SAILED', 'ARRIVED', 'DISCHARGED', 'GATE_OUT', 'CLEARED'] as $step) {
            $this->shipments->recordMilestone($shipmentId, $step);
        }
        $this->shipments->recordMilestone($shipmentId, 'DELIVERED');
        Shipment::find($shipmentId)->update(['status' => 'DISPUTED']);

        $this->assertSame(1, $this->q->disputed()['count']);
    }

    // ------------------------------------------------------ reconciliation

    public function test_a_healthy_wallet_reports_no_drift(): void
    {
        $wallet = app(WalletService::class);
        $wallet->ensureWallet('buyer-ok');
        $wallet->deposit('buyer-ok', 100000);
        $wallet->hold('buyer-ok', 40000, 'usd', ['id' => 'b1']);
        $wallet->release('buyer-ok', 40000, 'usd', ['id' => 'b1-out']);

        $this->assertSame(0, $this->q->reconciliationDrift()['count']);
    }

    public function test_drift_is_detected_when_balances_disagree_with_the_ledger(): void
    {
        $wallet = app(WalletService::class);
        $wallet->ensureWallet('buyer-drift');
        $wallet->deposit('buyer-drift', 100000);

        // Tamper with the cached balance, leaving the ledger intact.
        Wallet::where('user_id', 'buyer-drift')
            ->update(['balance_available' => 999999]);

        $result = $this->q->reconciliationDrift();

        $this->assertSame(1, $result['count']);
        $this->assertSame('buyer-drift', $result['rows'][0]->user_id);
    }

    public function test_a_captured_balance_is_not_reported_as_drift(): void
    {
        // The case that broke a naive SUM(amount): CAPTURE lowers the total,
        // HOLD and RELEASE are neutral. A wallet that has had money captured
        // out of it is healthy, not drifted.
        $wallet = app(WalletService::class);
        $wallet->ensureWallet('buyer-captured');
        $wallet->deposit('buyer-captured', 100000);
        $wallet->hold('buyer-captured', 60000, 'usd', ['id' => 'b1']);
        $wallet->capture('buyer-captured', 60000, 'usd', ['id' => 'settled']);

        $this->assertSame(0, $this->q->reconciliationDrift()['count']);
    }

    // --------------------------------------------------------- summary

    public function test_summary_counts_every_queue(): void
    {
        $summary = $this->q->summary();

        foreach (['needs_money', 'unallocated', 'needs_booking', 'in_transit', 'at_tema', 'disputed', 'drift'] as $queue) {
            $this->assertArrayHasKey($queue, $summary);
            $this->assertIsInt($summary[$queue]);
        }
    }
}