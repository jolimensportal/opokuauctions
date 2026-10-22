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
 * The customer portal.
 *
 * A buyer signs in and sees their own freight. It is a separate application
 * with its own chrome: the storefront's header and footer are not part of it,
 * and neither is Filament — a buyer must never touch operator software.
 */
class CustomerFreightPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/account/freight')->assertRedirect(route('login'));
    }

    public function test_a_buyer_sees_their_own_freight(): void
    {
        $buyer = User::factory()->create(['role' => Role::Buyer, 'email' => 'kwame@example.test']);

        $shipment = Shipment::create([
            'id' => 'shp_portal',
            'container_no' => 'MSCU 4471 908',
            'vessel' => 'MV Atlantic Trader',
            'seaport_origin' => 'Newark',
            'seaport_destination' => 'Tema',
            'status' => 'SAILING',
        ]);

        $lot = Lot::create([
            'id' => 'lot_portal',
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
            'highest_bidder_id' => $buyer->id,
            'bid_count' => 3,
            'extensions' => 0,
            'deposit_bps' => 2000,
        ]);

        ShipmentLot::create(['shipment_id' => $shipment->id, 'lot_id' => $lot->id, 'quantity' => 1]);

        $this->actingAs($buyer, 'web')
            ->get('/account/freight')
            ->assertOk()
            ->assertSee('2021 Ford F-150')
            ->assertSee('MSCU 4471 908');
    }

    public function test_the_portal_does_not_wear_the_storefront_chrome(): void
    {
        $buyer = User::factory()->create(['role' => Role::Buyer]);

        $html = (string) $this->actingAs($buyer, 'web')
            ->get('/account/freight')
            ->assertOk()
            ->getContent();

        // The storefront's ink band and its footer copy. If these appear, the
        // portal is wearing the shop's clothes, which is the mistake this page
        // exists to avoid.
        $this->assertStringNotContainsString('band-ink', $html);
        $this->assertStringNotContainsString('Affordable quality auto-auctions', $html);
    }
}
