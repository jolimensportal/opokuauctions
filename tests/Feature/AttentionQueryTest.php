<?php

namespace Tests\Feature;

use App\Enums\AttentionKind;
use App\Enums\Role;
use App\Models\ClearanceTask;
use App\Models\Lot;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use App\Models\ShipmentLotStatus;
use App\Models\User;
use App\Services\AttentionQuery;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every detector is tested twice: it fires in the broken state, and it is
 * ABSENT once the condition is repaired. The second half matters more. An
 * exception that can never clear is as broken as one that never fires — the
 * queue would fill with items nobody is able to resolve and would be abandoned.
 */
class AttentionQueryTest extends TestCase
{
    use RefreshDatabase;

    private AttentionQuery $query;

    private ShipmentService $shipments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = app(AttentionQuery::class);
        $this->shipments = app(ShipmentService::class);
    }

    /** A shipment with $count lots allocated. */
    private function shipment(int $count = 1, ?string $id = null): string
    {
        $payload = [
            'container_no' => 'MSKU'.uniqid(),
            'seaport_destination' => 'Tema',
            'freight_cost' => 900_00,
            'insurance_cost' => 100_00,
        ];

        // Only override the id when one was asked for. Passing an explicit null
        // would override the generated default with NULL and violate the key.
        if ($id !== null) {
            $payload['id'] = $id;
        }

        // createShipment() also writes the full Tema clearance template, so
        // every shipment already carries a DUTY_PAID, VAT_PAID and
        // PORT_CHARGES row.
        $shipment = $this->shipments->createShipment($payload);

        for ($i = 0; $i < $count; $i++) {
            ShipmentLot::create([
                'shipment_id' => $shipment->id,
                'lot_id' => $this->lot(['weight_kg' => 1500])->id,
                'quantity' => 1,
            ]);
        }

        return $shipment->id;
    }

    private function lot(array $overrides = []): Lot
    {
        return Lot::create(array_merge([
            'id' => 'lot_'.uniqid(),
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => 'Toyota Camry',
            'city' => 'Detroit',
            'photos' => [],
            'starting_bid' => 100000,
            'reserve_price' => 500000,
            'bid_increment' => 25000,
            'current_bid' => 100000,
            'currency_code' => 'usd',
            'status' => 'UPCOMING',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'weight_kg' => 1500,
        ], $overrides));
    }

    /** outbound_messages requires user_id and phone; neither is optional. */
    private function message(array $overrides = []): string
    {
        $id = $overrides['id'] ?? 'omb_'.uniqid();

        DB::table('outbound_messages')->insert(array_merge([
            'id' => $id,
            'user_id' => User::factory()->create(['role' => Role::Buyer])->id,
            'phone' => '+233200000000',
            'channel' => 'whatsapp',
            'message_kind' => 'transactional',
            'template' => 'you_won',
            'body' => 'You won a lot',
            'about_type' => 'lot',
            'about_id' => 'lot_1',
            'status' => 'queued',
            'attempts' => 0,
        ], $overrides));

        return $id;
    }

    private function wallet(array $overrides = []): string
    {
        $id = $overrides['id'] ?? 'wal_'.uniqid();

        DB::table('wallet')->insert(array_merge([
            'id' => $id,
            'user_id' => User::factory()->create(['role' => Role::Buyer])->id,
            'currency_code' => 'usd',
            'balance_available' => 0,
            'balance_held' => 0,
            'version' => 1,
        ], $overrides));

        return $id;
    }

    /**
     * Put the shipment's DUTY_PAID task into the given state.
     *
     * The task is already there from createShipment()'s template, so this
     * updates it rather than inserting — (shipment_id, type) is unique.
     */
    private function dutyTask(string $shipmentId, array $overrides): ClearanceTask
    {
        $task = ClearanceTask::where('shipment_id', $shipmentId)
            ->where('type', 'DUTY_PAID')
            ->firstOrFail();

        $task->update($overrides);

        return $task;
    }

    private function find(string $ref)
    {
        return collect($this->query->all())->firstWhere('ref', $ref);
    }

    private function findKind(AttentionKind $kind)
    {
        return collect($this->query->all())->first(fn ($i) => $i->kind === $kind);
    }

    /**
     * Find one item by kind AND ref.
     *
     * Both are needed. Two kinds can key off the same underlying row — a
     * shipment with no lots is both ShipmentUnallocated and, while its
     * template is still pending, ShipmentUncleared. Matching on ref alone
     * would return whichever came first and hide the other.
     */
    private function findBoth(AttentionKind $kind, string $ref)
    {
        return collect($this->query->all())
            ->first(fn ($i) => $i->kind === $kind && $i->ref === $ref);
    }

    // ---------------------------------------------- unevidenced money ----

    public function test_a_complete_money_task_with_no_receipt_is_critical(): void
    {
        $shipmentId = $this->shipment();

        $task = $this->dutyTask($shipmentId, [
            'status' => 'complete',
            'cost' => 42000,
            'receipt_path' => null,
        ]);

        $found = $this->find($task->id);

        $this->assertNotNull($found, 'A completed duty with no receipt must appear.');
        $this->assertSame(AttentionKind::UnevidencedMoney, $found->kind);
        $this->assertSame(4, $found->severity->weight(), 'Unevidenced money is Critical.');
    }

    public function test_attaching_a_receipt_clears_the_exception(): void
    {
        $shipmentId = $this->shipment();

        $task = $this->dutyTask($shipmentId, [
            'status' => 'complete',
            'cost' => 42000,
            'receipt_path' => null,
        ]);

        $this->assertNotNull(
            $this->find($task->id),
            'Precondition: the exception must fire before the repair.',
        );

        $task->update(['receipt_path' => 'receipts/duty.pdf']);

        // The repair must remove the item. This is the assertion that matters
        // most in this file.
        $this->assertNull(
            $this->find($task->id),
            'Attaching a receipt must clear the exception — there is no dismiss step.',
        );
    }

    public function test_an_empty_receipt_string_also_counts_as_missing(): void
    {
        $shipmentId = $this->shipment();

        $task = $this->dutyTask($shipmentId, [
            'status' => 'complete',
            'cost' => 42000,
            'receipt_path' => '',
        ]);

        // An empty string is no more evidence than NULL, and a form that
        // submits '' is the common way this happens in practice.
        $this->assertNotNull($this->find($task->id));
    }

    public function test_a_free_task_with_no_receipt_is_not_an_exception(): void
    {
        $shipmentId = $this->shipment();

        $free = ClearanceTask::where('shipment_id', $shipmentId)
            ->where('type', 'GATE_OUT')
            ->firstOrFail();

        $free->update([
            'status' => 'complete',
            'cost' => null,
            'receipt_path' => null,
        ]);

        // GATE_OUT does not cost money, so no receipt is owed for it. Flagging
        // it would bury the real exception under housekeeping noise.
        $this->assertNull($this->find($free->id));
    }

    public function test_an_incomplete_money_task_is_not_yet_an_exception(): void
    {
        $shipmentId = $this->shipment();

        $task = $this->dutyTask($shipmentId, [
            'status' => 'pending',
            'cost' => 42000,
            'receipt_path' => null,
        ]);

        // Duty not yet paid is an open task, not an unaccounted spend. Only
        // completed-but-unreceipted is the exception.
        $this->assertNull($this->find($task->id));
    }

    // ------------------------------------------------ shipment issues ----

    public function test_a_shipment_with_no_lots_is_flagged(): void
    {
        $shipmentId = $this->shipment(0, 'shp_empty');

        $this->assertNotNull(
            $this->findBoth(AttentionKind::ShipmentUnallocated, 'shp_empty'),
            'Freight cannot be divided for a shipment carrying nothing.',
        );
    }

    public function test_allocating_a_lot_clears_the_unallocated_exception(): void
    {
        $shipmentId = $this->shipment(0, 'shp_filled');

        // A fresh shipment is also uncleared — its nine-task template is all
        // pending — so this ref carries two different exceptions until the
        // lot is added. Only the unallocated one should disappear.
        $this->assertNotNull($this->findBoth(AttentionKind::ShipmentUnallocated, 'shp_filled'));

        ShipmentLot::create([
            'shipment_id' => $shipmentId,
            'lot_id' => $this->lot()->id,
            'quantity' => 1,
        ]);

        $this->assertNull(
            $this->findBoth(AttentionKind::ShipmentUnallocated, 'shp_filled'),
            'Allocating a lot clears the unallocated exception.',
        );
        $this->assertNotNull(
            $this->findBoth(AttentionKind::ShipmentUncleared, 'shp_filled'),
            'Clearing allocation must not silently clear the separate uncleared exception.',
        );
    }

    public function test_an_uncleared_shipment_is_flagged(): void
    {
        $shipmentId = $this->shipment(1, 'shp_uncleared');

        $this->dutyTask($shipmentId, ['status' => 'pending', 'cost' => 42000]);

        $found = $this->findBoth(AttentionKind::ShipmentUncleared, 'shp_uncleared');

        $this->assertNotNull($found);
    }

    public function test_clearing_every_task_clears_the_shipment_exception(): void
    {
        $shipmentId = $this->shipment(1, 'shp_cleared');

        // Clear every task the template created, not just the duty one.
        ClearanceTask::where('shipment_id', $shipmentId)->update([
            'status' => 'complete',
            'receipt_path' => 'receipts/duty.pdf',
        ]);

        $this->assertNull($this->findBoth(AttentionKind::ShipmentUncleared, 'shp_cleared'));
    }

    public function test_a_fully_cleared_and_allocated_shipment_is_not_flagged(): void
    {
        $shipmentId = $this->shipment(1, 'shp_all_good');

        ClearanceTask::where('shipment_id', $shipmentId)->update([
            'status' => 'complete',
            'receipt_path' => 'receipts/duty.pdf',
        ]);

        $this->assertNull($this->findBoth(AttentionKind::ShipmentUncleared, 'shp_all_good'));
    }

    // --------------------------------------------- open lot exception ----

    public function test_an_unresolved_lot_exception_is_flagged(): void
    {
        $shipmentId = $this->shipment();
        $lot = $this->lot(['id' => 'lot_damaged']);

        $link = ShipmentLot::create([
            'shipment_id' => $shipmentId,
            'lot_id' => $lot->id,
            'quantity' => 1,
        ]);

        ShipmentLotStatus::create([
            'shipment_lot_id' => $link->id,
            'status' => 'DAMAGED',
            'note' => 'Tailgate dented in transit',
            'resolved_at' => null,
        ]);

        $this->assertNotNull($this->findKind(AttentionKind::OpenLotException));
    }

    public function test_resolving_a_lot_exception_clears_it(): void
    {
        $shipmentId = $this->shipment();

        $link = ShipmentLot::create([
            'shipment_id' => $shipmentId,
            'lot_id' => $this->lot(['id' => 'lot_ok'])->id,
            'quantity' => 1,
        ]);

        $status = ShipmentLotStatus::create([
            'shipment_lot_id' => $link->id,
            'status' => 'DAMAGED',
            'resolved_at' => null,
        ]);

        $this->assertNotNull($this->findKind(AttentionKind::OpenLotException));

        $status->update(['resolved_at' => now()]);

        $this->assertNull($this->findKind(AttentionKind::OpenLotException));
    }

    // ------------------------------------------------- failed message ----

    public function test_a_failed_outbound_message_is_flagged(): void
    {
        $id = $this->message(['id' => 'omb_failed', 'status' => 'failed', 'attempts' => 3, 'last_error' => 'SMTP timeout']);

        $this->assertNotNull($this->find($id));
    }

    public function test_a_delivered_message_is_not_flagged(): void
    {
        $id = $this->message(['id' => 'omb_sent', 'status' => 'sent', 'attempts' => 1]);

        $this->assertNull($this->find($id));
    }

    public function test_a_queued_message_is_not_flagged(): void
    {
        $id = $this->message(['id' => 'omb_queued', 'status' => 'queued']);

        // Queued is the normal state of a message still in flight.
        $this->assertNull($this->find($id));
    }

    // -------------------------------------------------- lot unweighed ----

    public function test_a_lot_with_no_weight_is_flagged(): void
    {
        $this->lot(['id' => 'lot_no_weight', 'weight_kg' => null]);

        $found = $this->find('lot_no_weight');

        $this->assertNotNull($found, 'A lot nobody weighed cannot be freighted.');
        $this->assertSame(AttentionKind::LotUnweighed, $found->kind);
    }

    public function test_weighing_a_lot_clears_the_exception(): void
    {
        $lot = $this->lot(['id' => 'lot_weighed_later', 'weight_kg' => null]);

        $this->assertNotNull($this->find('lot_weighed_later'));

        $lot->update(['weight_kg' => 1520]);

        $this->assertNull($this->find('lot_weighed_later'));
    }

    public function test_an_unweighed_lot_on_a_sailing_container_is_critical(): void
    {
        $shipmentId = $this->shipment(0, 'shp_sailing');

        $lot = $this->lot(['id' => 'lot_unweighed_sailing', 'weight_kg' => null]);
        ShipmentLot::create([
            'shipment_id' => $shipmentId,
            'lot_id' => $lot->id,
            'quantity' => 1,
        ]);

        Shipment::where('id', $shipmentId)->update(['status' => 'SAILING']);

        $found = $this->find('lot_unweighed_sailing');

        $this->assertNotNull($found);
        $this->assertSame(
            4,
            $found->severity->weight(),
            'A container cannot sail with a lot whose weight is unknown.',
        );
    }

    public function test_an_unweighed_lot_that_is_not_aboard_is_only_medium(): void
    {
        $this->lot(['id' => 'lot_loose', 'weight_kg' => null]);

        $this->assertSame(2, $this->find('lot_loose')->severity->weight());
    }

    public function test_settled_lots_are_not_reported_as_unweighed(): void
    {
        // A closed lot will never be freighted again, so its weight no longer
        // blocks anything. Reporting it would grow the queue without bound.
        $this->lot(['id' => 'lot_settled', 'weight_kg' => null, 'status' => 'SETTLED']);

        $this->assertNull($this->find('lot_settled'));
    }

    // ---------------------------------------------------- wallet held ----

    public function test_held_funds_are_flagged(): void
    {
        $id = $this->wallet(['id' => 'wal_held', 'balance_held' => 50000]);

        $this->assertNotNull($this->find($id));
    }

    public function test_an_empty_wallet_is_not_flagged(): void
    {
        $id = $this->wallet(['id' => 'wal_clear', 'balance_available' => 100000, 'balance_held' => 0]);

        $this->assertNull($this->find($id));
    }

    // ----------------------------------------------------- composition ----

    public function test_the_queue_is_ordered_most_severe_first(): void
    {
        $shipmentId = $this->shipment(1, 'shp_order');

        $this->lot(['id' => 'lot_order_1', 'weight_kg' => null, 'status' => 'UPCOMING']);
        $this->wallet(['id' => 'wal_order', 'balance_held' => 100]);
        $this->message(['id' => 'omb_order', 'status' => 'failed', 'attempts' => 1]);

        $this->dutyTask($shipmentId, [
            'status' => 'complete', 'cost' => 42000, 'receipt_path' => null,
        ]);

        $weights = $this->query->all()->map(fn ($i) => $i->severity->weight())->all();

        $sorted = $weights;
        rsort($sorted);

        $this->assertSame($sorted, $weights, 'The queue must be ordered most severe first.');
    }

    public function test_the_count_matches_the_number_of_rows(): void
    {
        $this->lot(['id' => 'lot_count', 'weight_kg' => null]);

        // The badge and the queue are the same fact. If these disagree the
        // operator trusts a number that does not describe the list beneath it.
        $this->assertSame(
            count($this->query->all()),
            $this->query->count(),
            'Badge count must equal the number of rows shown.',
        );
    }

    public function test_severity_counts_add_up_to_the_queue(): void
    {
        $shipmentId = $this->shipment(1, 'shp_sevcount');

        $this->lot(['id' => 'lot_sevcount', 'weight_kg' => null]);
        $this->dutyTask($shipmentId, [
            'status' => 'complete', 'cost' => 42000, 'receipt_path' => null,
        ]);

        $this->assertSame(
            $this->query->count(),
            array_sum($this->query->countBySeverity()),
            'The stat tiles must account for every row.',
        );
    }

    public function test_a_broken_detector_does_not_silently_empty_the_queue(): void
    {
        $this->lot(['id' => 'lot_present', 'weight_kg' => null]);

        // Simulate a detector failing by dropping the table it reads.
        Schema::drop('wallet');

        $result = $this->query->allWithHealth();

        // The healthy items still come back...
        $this->assertNotNull(
            collect($result['items'])->firstWhere('ref', 'lot_present'),
            'One failing detector must not empty the whole queue.',
        );

        // ...and the failure is reported rather than hidden.
        $this->assertNotEmpty($result['failed'], 'A failing detector must be reported, not swallowed.');
        $this->assertArrayHasKey('wallet_held', $result['failed']);
    }
}