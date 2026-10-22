<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ClearanceTask;
use App\Models\Lot;
use App\Models\ParcelBooking;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use App\Models\ShipmentMilestone;
use App\Models\User;
use App\Services\CustomerFreight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A buyer's freight is what they are waiting for.
 *
 * Their cars are the lots they won, joined to the container carrying them.
 * Their parcels are the bookings they sent. Nobody else's.
 */
class CustomerFreightServiceTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(): User
    {
        return User::factory()->create(['role' => Role::Buyer, 'email' => 'kwame@example.test']);
    }

    private function containerFor(User $winner): Shipment
    {
        $shipment = Shipment::create([
            'id' => 'shp_cust',
            'container_no' => 'MSCU 4471 908',
            'vessel' => 'MV Atlantic Trader',
            'seaport_origin' => 'Newark',
            'seaport_destination' => 'Tema',
            'status' => 'SAILING',
        ]);

        $lot = Lot::create([
            'id' => 'lot_cust',
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => '2021 Ford F-150',
            'city' => 'Newark',
            'photos' => [],
            'currency_code' => 'usd',
            'starting_bid' => 100000,
            'reserve_price' => 0,
            'bid_increment' => 1000,
            'starts_at' => now(),
            'ends_at' => now()->addDay(),
            'current_bid' => 385000,
            'highest_bidder_id' => $winner->id,
            'bid_count' => 3,
            'extensions' => 0,
            'deposit_bps' => 2000,
        ]);

        ShipmentLot::create(['shipment_id' => $shipment->id, 'lot_id' => $lot->id, 'quantity' => 1]);

        ShipmentMilestone::create([
            'id' => 'ms_cust',
            'shipment_id' => $shipment->id,
            'type' => 'SAILING',
            'occurred_at' => now()->subDay(),
            'source' => 'admin',
        ]);

        ClearanceTask::create([
            'id' => 'clt_cust',
            'shipment_id' => $shipment->id,
            'type' => 'DUTY_PAID',
            'position' => 3,
            'status' => 'complete',
            'cost' => 94000,
        ]);

        return $shipment;
    }

    public function test_it_finds_the_cars_a_buyer_won(): void
    {
        $buyer = $this->buyer();
        $this->containerFor($buyer);

        $freight = app(CustomerFreight::class)->for($buyer);

        $this->assertCount(1, $freight['cars']);
        $this->assertSame('2021 Ford F-150', $freight['cars'][0]['title']);
        $this->assertSame('MSCU 4471 908', $freight['cars'][0]['container_no']);
    }

    public function test_it_never_shows_another_buyers_car(): void
    {
        $buyer = $this->buyer();
        $other = User::factory()->create(['role' => Role::Buyer]);
        $this->containerFor($other);

        $freight = app(CustomerFreight::class)->for($buyer);

        $this->assertCount(0, $freight['cars']);
    }

    public function test_it_counts_the_cars_arriving(): void
    {
        $buyer = $this->buyer();
        $this->containerFor($buyer);

        $freight = app(CustomerFreight::class)->for($buyer);

        $this->assertSame(1, $freight['counters']['carsArriving']);
    }

    public function test_it_finds_the_parcels_a_buyer_sent(): void
    {
        $buyer = $this->buyer();

        ParcelBooking::create([
            'id' => 'bk_cust',
            'reference' => 'refcust',
            'status' => 'payment_pending',
            'email' => $buyer->email,
            'user_id' => $buyer->id,
            'country' => 'us',
            'postcode' => '07030',
            'unit' => 'parcel',
            'weight_grams' => 20000,
            'pickup' => false,
            'quoted_total_cents' => 2500,
            'rate_card_row_id' => 'row-newark-air-standard',
            'quote_input' => ['unit' => 'parcel'],
            'expires_at' => now()->addDay(),
        ]);

        $freight = app(CustomerFreight::class)->for($buyer);

        $this->assertCount(1, $freight['parcels']);
        $this->assertCount(1, $freight['actions']);
        $this->assertStringContainsString('$25.00', $freight['actions'][0]['title']);
    }

    public function test_it_never_shows_another_buyers_parcel(): void
    {
        $buyer = $this->buyer();

        ParcelBooking::create([
            'id' => 'bk_other',
            'reference' => 'refother',
            'status' => 'payment_pending',
            'email' => 'someone-else@example.test',
            'country' => 'us',
            'postcode' => '07030',
            'unit' => 'parcel',
            'weight_grams' => 20000,
            'pickup' => false,
            'quoted_total_cents' => 2500,
            'rate_card_row_id' => 'row-newark-air-standard',
            'quote_input' => ['unit' => 'parcel'],
            'expires_at' => now()->addDay(),
        ]);

        $freight = app(CustomerFreight::class)->for($buyer);

        $this->assertCount(0, $freight['parcels']);
    }

    public function test_it_sums_the_freight_billed_on_their_car(): void
    {
        $buyer = $this->buyer();
        $this->containerFor($buyer);

        $freight = app(CustomerFreight::class)->for($buyer);

        $this->assertSame(94000, $freight['freightBilledCents']);
    }
}
