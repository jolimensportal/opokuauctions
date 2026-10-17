<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Lot;
use App\Models\OutboundMessage;
use App\Models\ShipmentMilestone;
use App\Models\User;
use App\Notifications\CorridorNotifier;
use App\Notifications\WhatsApp\FakeWhatsAppGateway;
use App\Notifications\WhatsApp\WhatsAppGateway;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * WhatsApp is how this corridor actually communicates, so these tests are
 * about one rule above all others: a messaging failure must never be able to
 * break a shipment. The container moved regardless of whether the message sent.
 */
class WhatsAppNotificationTest extends TestCase
{
    use RefreshDatabase;

    private ShipmentService $shipments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shipments = app(ShipmentService::class);
    }

    private function bindFakeGateway(bool $shouldFail = false): FakeWhatsAppGateway
    {
        $gateway = new FakeWhatsAppGateway();
        $gateway->shouldFail = $shouldFail;
        $this->app->instance(WhatsAppGateway::class, $gateway);

        return $gateway;
    }

    /** A paid lot aboard a container, with a reachable buyer. */
    private function shipmentWithBuyer(?string $phone = '+233201234567'): string
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'phone' => $phone,
        ]);

        $lot = Lot::create([
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
            'balance_settled_at' => now(),
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'status' => 'SOLD',
            'current_bid' => 600000,
            'highest_bidder_id' => $buyer->id,
            'bid_count' => 4,
        ]);

        $shipmentId = $this->shipments->createShipment([
            'container_no' => 'MSKU'.uniqid(),
            'seaport_destination' => 'Tema',
            'eta' => now()->addDays(21),
        ])->id;

        $this->shipments->allocateLot($shipmentId, $lot->id);

        return $shipmentId;
    }

    // ------------------------------------------------------------- queuing

    public function test_a_clearing_milestone_queues_a_message_for_the_buyer(): void
    {
        Queue::fake();

        $shipmentId = $this->shipmentWithBuyer();

        foreach (['LOADED', 'SAILED', 'ARRIVED', 'DISCHARGED', 'GATE_OUT', 'CLEARED'] as $step) {
            $this->shipments->recordMilestone($shipmentId, $step);
        }

        $message = OutboundMessage::where('about_id', $shipmentId)
            ->where('template', 'shipment_cleared')
            ->first();

        $this->assertNotNull($message, 'expected a queued message for CLEARED');
        $this->assertSame('queued', $message->status);
        $this->assertSame('shipment_cleared', $message->template);
        $this->assertStringContainsString('CLEARED customs', $message->body);
    }

    public function test_uninteresting_milestones_do_not_message_the_buyer(): void
    {
        Queue::fake();

        $shipmentId = $this->shipmentWithBuyer();
        $this->shipments->recordMilestone($shipmentId, 'LOADED');

        $this->assertSame(0, OutboundMessage::count());
    }

    public function test_a_buyer_without_a_phone_is_skipped_not_fatal(): void
    {
        Queue::fake();

        $shipmentId = $this->shipmentWithBuyer(phone: null);

        foreach (['LOADED', 'SAILED', 'ARRIVED', 'DISCHARGED', 'GATE_OUT', 'CLEARED'] as $step) {
            $this->shipments->recordMilestone($shipmentId, $step);
        }

        $this->assertSame(0, OutboundMessage::count());
        $this->assertSame('CLEARED', \App\Models\Shipment::find($shipmentId)->status);
    }

    public function test_the_same_milestone_is_not_queued_twice_for_one_buyer(): void
    {
        Queue::fake();

        $shipmentId = $this->shipmentWithBuyer();
        $notifier = app(CorridorNotifier::class);
        $milestone = ShipmentMilestone::create([
            'id' => 'ms_test_1', 'shipment_id' => $shipmentId, 'type' => 'CLEARED',
            'occurred_at' => now(), 'source' => 'admin',
        ]);

        $first = $notifier->notifyShipmentMilestone($milestone);
        $second = $notifier->notifyShipmentMilestone($milestone);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'the same milestone must not re-notify');
        $this->assertSame(1, OutboundMessage::count());
    }

    // ----------------------------------------------------------- non-blocking

    public function test_a_provider_failure_does_not_break_the_milestone(): void
    {
        Queue::fake();

        $gateway = $this->bindFakeGateway(shouldFail: true);
        $shipmentId = $this->shipmentWithBuyer();

        // Drive the whole journey. The messaging layer is broken throughout.
        foreach (['LOADED', 'SAILED', 'ARRIVED', 'DISCHARGED', 'GATE_OUT', 'CLEARED', 'DELIVERED'] as $step) {
            $this->shipments->recordMilestone($shipmentId, $step);
        }

        $shipment = \App\Models\Shipment::find($shipmentId);

        $this->assertSame('DELIVERED', $shipment->status, 'a failed message must not stop the shipment');
        $this->assertGreaterThan(0, OutboundMessage::count(), 'the messages must still be recorded');

        // Now actually run the sends against the broken provider. The shipment
        // must already be DELIVERED and must stay that way.
        foreach (OutboundMessage::all() as $message) {
            (new SendWhatsAppMessage($message->id))->handle($gateway);
            $this->assertSame('failed', $message->refresh()->status);
        }

        $this->assertSame(
            'DELIVERED',
            \App\Models\Shipment::find($shipmentId)->status,
            'recording send failures must not reopen or un-complete the shipment'
        );
    }

    public function test_a_failed_send_is_recorded_with_its_reason(): void
    {
        Queue::fake();

        $gateway = $this->bindFakeGateway(shouldFail: true);
        $shipmentId = $this->shipmentWithBuyer();

        foreach (['LOADED', 'SAILED', 'ARRIVED', 'DISCHARGED', 'GATE_OUT', 'CLEARED'] as $step) {
            $this->shipments->recordMilestone($shipmentId, $step);
        }

        $message = OutboundMessage::where('template', 'shipment_cleared')->first();
        (new SendWhatsAppMessage($message->id))->handle($gateway);

        $message->refresh();

        $this->assertSame('failed', $message->status);
        $this->assertSame('Simulated provider failure', $message->last_error);
        $this->assertNotNull($message->failed_at);
        $this->assertSame(1, $message->attempts);
    }

    public function test_a_successful_send_records_the_provider_id(): void
    {
        Queue::fake();

        $gateway = $this->bindFakeGateway();
        $shipmentId = $this->shipmentWithBuyer();

        foreach (['LOADED', 'SAILED', 'ARRIVED', 'DISCHARGED', 'GATE_OUT', 'CLEARED'] as $step) {
            $this->shipments->recordMilestone($shipmentId, $step);
        }

        // Only the CLEARED message is sent, so the count is unambiguous.
        $message = OutboundMessage::where('template', 'shipment_cleared')->first();
        (new SendWhatsAppMessage($message->id))->handle($gateway);

        $message->refresh();

        $this->assertSame('sent', $message->status);
        $this->assertNotNull($message->provider_message_id);
        $this->assertNotNull($message->sent_at);
        $this->assertNull($message->last_error);
        $this->assertCount(1, $gateway->sent);
    }

    public function test_the_job_does_nothing_for_an_already_delivered_message(): void
    {
        Queue::fake();

        $gateway = $this->bindFakeGateway();
        $shipmentId = $this->shipmentWithBuyer();

        foreach (['LOADED', 'SAILED', 'ARRIVED', 'DISCHARGED', 'GATE_OUT', 'CLEARED'] as $step) {
            $this->shipments->recordMilestone($shipmentId, $step);
        }

        $message = OutboundMessage::where('template', 'shipment_cleared')->first();
        $message->update(['status' => 'delivered', 'delivered_at' => now()]);

        (new SendWhatsAppMessage($message->id))->handle($gateway);

        $this->assertCount(0, $gateway->sent, 'a delivered message must not be re-sent');
    }

    // -------------------------------------------------------------- delivery

    public function test_messages_are_session_kind_because_bodies_are_dynamic(): void
    {
        Queue::fake();

        $shipmentId = $this->shipmentWithBuyer();

        foreach (['LOADED', 'SAILED', 'ARRIVED', 'DISCHARGED', 'GATE_OUT', 'CLEARED'] as $step) {
            $this->shipments->recordMilestone($shipmentId, $step);
        }

        $message = OutboundMessage::first();

        // Live amounts and dates cannot go out as pre-approved templates.
        $this->assertSame('session', $message->message_kind);
    }

    public function test_a_dispute_tells_the_buyer_their_money_is_held(): void
    {
        Queue::fake();

        $shipmentId = $this->shipmentWithBuyer();
        $buyer = User::where('role', 'buyer')->firstOrFail();

        app(CorridorNotifier::class)->notifyDispute(
            \App\Models\Shipment::find($shipmentId),
            $buyer,
            'customs query on VIN'
        );

        $message = OutboundMessage::where('template', 'shipment_disputed')->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('held, not lost', $message->body);
    }
}