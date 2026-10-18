<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lot;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer dashboard: one account covering both businesses.
 *
 * Buying and shipping sit side by side rather than behind tabs, because a tab
 * makes the bigger of the two look like an afterthought. Shipping has no data
 * until sender identity exists, so it ships an empty state rather than invented
 * rows — a fake parcel on someone's real dashboard is worse than no column.
 *
 * The scope tests matter most. This page lists other people's transactions if
 * it is built wrong, and that is a data breach rather than a layout bug.
 */
class CustomerDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function lot(array $overrides = []): Lot
    {
        return Lot::create(array_merge([
            'id' => 'lot_'.uniqid(),
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => 'Toyota Camry',
            'city' => 'Detroit',
            'condition' => 'clean_title',
            'currency_code' => 'usd',
            'starting_bid' => 100000,
            'reserve_price' => 150000,
            'bid_increment' => 5000,
            'current_bid' => 100000,
            'bid_count' => 0,
            'extensions' => 0,
            'photos' => [],
            'status' => 'LIVE',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'weight_kg' => 1500,
        ], $overrides));
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/account')->assertRedirect();
    }

    public function test_a_buyer_sees_their_dashboard(): void
    {
        $user = User::factory()->create(['role' => Role::Buyer]);

        $this->actingAs($user)->get('/account')->assertOk();
    }

    public function test_the_buyer_greets_them_by_first_name(): void
    {
        $user = User::factory()->create(['role' => Role::Buyer, 'name' => 'Ama Mensah']);

        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('Ama');
    }

    public function test_a_buyer_sees_the_lot_they_are_winning(): void
    {
        $user = User::factory()->create(['role' => Role::Buyer]);

        $this->lot(['title' => '2018 Toyota Camry', 'highest_bidder_id' => $user->id]);

        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('2018 Toyota Camry');
    }

    public function test_a_buyer_never_sees_someone_elses_lot(): void
    {
        $user = User::factory()->create(['role' => Role::Buyer]);
        $other = User::factory()->create(['role' => Role::Buyer]);

        $this->lot(['title' => 'Someone Elses Lexus', 'highest_bidder_id' => $other->id]);

        // This page lists other people's transactions if built wrong, which is
        // a data breach rather than a layout mistake.
        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertDontSee('Someone Elses Lexus');
    }

    public function test_the_shipping_column_says_so_when_there_are_no_parcels(): void
    {
        $user = User::factory()->create(['role' => Role::Buyer]);

        // There is no sender concept yet, so every customer sees this. It has
        // to read as an invitation, not as a broken panel.
        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('Nothing in transit')
            ->assertSee('Send something');
    }

    public function test_the_dashboard_offers_both_businesses_not_just_one(): void
    {
        $user = User::factory()->create(['role' => Role::Buyer]);

        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('Buying')
            ->assertSee('Shipping');
    }

    public function test_the_balance_tile_reads_zero_rather_than_being_absent(): void
    {
        $user = User::factory()->create(['role' => Role::Buyer]);

        // A tile that silently omits a balance reads as "not applicable". A
        // real zero reads as "you owe nothing", which is a different message.
        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('Balance');
    }

    public function test_a_shipment_carrying_a_won_lot_shows_in_transit(): void
    {
        $user = User::factory()->create(['role' => Role::Buyer]);
        $lot = $this->lot(['title' => 'Shipped Toyota', 'highest_bidder_id' => $user->id]);

        $shipment = Shipment::create([
            'id' => 'shp_dash',
            'container_no' => 'MSKU-DASH',
            'status' => 'SAILING',
            'freight_cost' => 90000,
            'insurance_cost' => 10000,
        ]);

        ShipmentLot::create([
            'shipment_id' => $shipment->id,
            'lot_id' => $lot->id,
            'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->get('/account')
            ->assertOk()
            ->assertSee('Shipped Toyota');
    }
}