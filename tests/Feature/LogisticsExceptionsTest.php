<?php

namespace Tests\Feature;

use App\Models\Forwarder;
use App\Models\ParcelBooking;
use App\Models\Shipment;
use App\Models\ShipmentMilestone;
use App\Services\LogisticsExceptions;
use App\Services\ShipmentStaleness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The queue of things that are stopped.
 *
 * Every item must name a next action and be countable, because a queue nobody
 * can act on is a dashboard decoration.
 */
class LogisticsExceptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_container_that_has_not_moved_for_three_days_is_an_exception(): void
    {
        Forwarder::create(['id' => 'fwd_ok', 'name' => 'Atlantic Freight']);

        $shipment = Shipment::create([
            'id' => 'shp_silent',
            'container_no' => 'MSCU 0000 001',
            'status' => 'SAILING',
            'forwarder_id' => 'fwd_ok',
        ]);

        ShipmentMilestone::create([
            'id' => 'ms_silent',
            'shipment_id' => $shipment->id,
            'type' => 'SAILING',
            'occurred_at' => now()->subDays(4),
            'source' => 'admin',
        ]);

        $exceptions = app(LogisticsExceptions::class)->all();

        $this->assertCount(1, $exceptions);
        $this->assertSame('silent_container', $exceptions[0]['kind']);
        $this->assertStringContainsString('MSCU 0000 001', $exceptions[0]['title']);
        $this->assertNotEmpty($exceptions[0]['action']);
        // Every item carries a short label as well as a next action, so the
        // row can be read at a glance and acted on without reading it.
        $this->assertNotEmpty($exceptions[0]['tag']);
    }

    public function test_a_container_that_moved_yesterday_is_not_an_exception(): void
    {
        Forwarder::create(['id' => 'fwd_ok', 'name' => 'Atlantic Freight']);

        $shipment = Shipment::create([
            'id' => 'shp_fresh',
            'container_no' => 'MSCU 0000 002',
            'status' => 'SAILING',
            'forwarder_id' => 'fwd_ok',
        ]);

        ShipmentMilestone::create([
            'id' => 'ms_fresh',
            'shipment_id' => $shipment->id,
            'type' => 'SAILING',
            'occurred_at' => now()->subDay(),
            'source' => 'admin',
        ]);

        $this->assertCount(0, app(LogisticsExceptions::class)->all());
    }

    public function test_a_container_still_being_booked_is_not_silent(): void
    {
        Forwarder::create(['id' => 'fwd_ok', 'name' => 'Atlantic Freight']);

        Shipment::create([
            'id' => 'shp_new',
            'container_no' => 'MSCU 0000 004',
            'status' => 'BOOKING',
            'forwarder_id' => 'fwd_ok',
        ]);

        // It has never moved, but it has not started either. A container
        // that was booked an hour ago is not a support queue.
        $this->assertCount(0, app(LogisticsExceptions::class)->all());
    }

    public function test_a_container_with_no_forwarder_is_an_exception(): void
    {
        Shipment::create([
            'id' => 'shp_nofwd',
            'container_no' => 'MSCU 0000 003',
            'status' => 'BOOKING',
            'forwarder_id' => null,
        ]);

        $exceptions = app(LogisticsExceptions::class)->all();

        $this->assertCount(1, $exceptions);
        $this->assertSame('missing_forwarder', $exceptions[0]['kind']);
    }

    public function test_an_expired_parcel_hold_is_an_exception(): void
    {
        ParcelBooking::create([
            'id' => 'bk_expired',
            'reference' => 'refexpired',
            'status' => 'payment_pending',
            'email' => 'buyer@example.test',
            'country' => 'us',
            'postcode' => '07030',
            'unit' => 'parcel',
            'weight_grams' => 20000,
            'pickup' => false,
            'quoted_total_cents' => 2500,
            'rate_card_row_id' => 'row-newark-air-standard',
            'quote_input' => ['unit' => 'parcel', 'weight_kg' => 20],
            'expires_at' => now()->subDay(),
        ]);

        $exceptions = app(LogisticsExceptions::class)->all();

        $this->assertCount(1, $exceptions);
        $this->assertSame('expired_hold', $exceptions[0]['kind']);
    }

    public function test_a_hold_that_has_not_expired_is_not_an_exception(): void
    {
        ParcelBooking::create([
            'id' => 'bk_live',
            'reference' => 'reflive',
            'status' => 'payment_pending',
            'email' => 'buyer@example.test',
            'country' => 'us',
            'postcode' => '07030',
            'unit' => 'parcel',
            'weight_grams' => 20000,
            'pickup' => false,
            'quoted_total_cents' => 2500,
            'rate_card_row_id' => 'row-newark-air-standard',
            'quote_input' => ['unit' => 'parcel', 'weight_kg' => 20],
            'expires_at' => now()->addDay(),
        ]);

        $this->assertCount(0, app(LogisticsExceptions::class)->all());
    }

    public function test_the_staleness_rule_is_one_rule(): void
    {
        $this->assertSame(3, ShipmentStaleness::DAYS);
    }
}
