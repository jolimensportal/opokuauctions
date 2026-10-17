<?php

namespace Tests\Feature;

use App\Models\FreightRate;
use App\Models\Lot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The freight estimate a buyer toggles on the lot page.
 *
 * Three properties this endpoint owes the buyer, all of them about honesty
 * rather than arithmetic:
 *
 *  1. **Always 200.** A buyer who cannot be quoted is told so in the body, not
 *     by staring at a failed request. The toggle is on a page they are already
 *     reading; an error status there reads as the site being broken.
 *
 *  2. **Never writes.** It is asked on every toggle, from every device, and it
 *     is not an order. A quote that left a row behind would eventually be
 *     mistaken for a charge.
 *
 *  3. **Readable by anyone.** It sits on a public listing page, so it is
 *     public, and it must not require an account to be created before a buyer
 *     can see what collection will cost them.
 */
class FreightEstimateEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** The live corridor. */
    private function rate(array $overrides = []): FreightRate
    {
        return FreightRate::create(array_merge([
            'lane' => 'na-tema',
            'usd_per_kg' => 0.32,
            'min_charge_cents' => 25000,
            'insurance_cents' => 10700,
            'pickup_cents' => 15000,
            'active' => true,
        ], $overrides));
    }

    private function lot(float|int|null $weightKg = 1800): Lot
    {
        return Lot::create([
            'id' => 'lot_'.uniqid(),
            'vin' => 'VIN-'.uniqid(),
            'year' => 2018,
            'make' => 'Honda',
            'model' => 'Accord',
            'city' => 'Detroit',
            'photos' => [],
            'starting_bid' => 100000,
            'reserve_price' => 500000,
            'bid_increment' => 25000,
            'deposit_bps' => 2000,
            'curb_weight_kg' => $weightKg,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'status' => 'LIVE',
        ]);
    }

    // ------------------------------------------------------------- the contract

    public function test_a_signed_out_buyer_can_ask_for_an_estimate(): void
    {
        $this->rate();

        // No actingAs(): the estimate sits on a public listing page.
        $this->getJson('/lots/'.$this->lot(1800)->id.'/freight-estimate?pickup=0')
            ->assertOk();
    }

    public function test_the_response_carries_exactly_the_documented_keys(): void
    {
        $this->rate();

        $response = $this->getJson('/lots/'.$this->lot(1800)->id.'/freight-estimate?pickup=0')
            ->assertOk()
            ->json();

        // The page reads these by name, so the set is part of the contract: a
        // renamed key is a blank card, not a bug anyone would notice.
        $this->assertSame([
            'weightKg',
            'freightCents',
            'insuranceCents',
            'pickupCents',
            'totalCents',
            'currencyCode',
            'available',
        ], array_keys($response));

        // JSON has one number type, so a whole weight comes back as 1800 rather
        // than 1800.0. The cast says so rather than letting the test quietly
        // decide that either is fine.
        $this->assertSame(1800.0, (float) $response['weightKg']);
        $this->assertSame(57600, $response['freightCents']);
        $this->assertSame(10700, $response['insuranceCents']);
        $this->assertSame(0, $response['pickupCents']);
        $this->assertSame(68300, $response['totalCents']);
        $this->assertSame('usd', $response['currencyCode']);
        $this->assertTrue($response['available']);
    }

    public function test_collecting_the_car_yourself_costs_nothing_to_fetch(): void
    {
        $this->rate();

        $this->getJson('/lots/'.$this->lot(1800)->id.'/freight-estimate?pickup=0')
            ->assertOk()
            ->assertJson([
                'pickupCents' => 0,
                'totalCents' => 68300,
                'available' => true,
            ]);
    }

    public function test_asking_for_pickup_adds_the_surcharge(): void
    {
        $this->rate();

        $this->getJson('/lots/'.$this->lot(1800)->id.'/freight-estimate?pickup=1')
            ->assertOk()
            ->assertJson([
                'pickupCents' => 15000,
                'totalCents' => 83300,
                'available' => true,
            ]);
    }

    public function test_an_absent_pickup_flag_means_the_buyer_collects_their_own_car(): void
    {
        $this->rate();

        // The toggle defaults to bring-yourself server-side, so a bare request
        // and pickup=0 must agree.
        $this->getJson('/lots/'.$this->lot(1800)->id.'/freight-estimate')
            ->assertOk()
            ->assertJson(['pickupCents' => 0]);
    }

    // ------------------------------------------------------------- unquotable

    public function test_an_unweighed_lot_is_reported_as_unavailable_at_two_hundred(): void
    {
        $this->rate();

        $response = $this->getJson('/lots/'.$this->lot(null)->id.'/freight-estimate?pickup=0')
            ->assertOk();

        // 200, not 404 or 422: the card has to be able to say "we cannot quote
        // this yet" without the request itself looking like a failure.
        $response->assertJson(['available' => false]);
        $this->assertFalse($response->json('available'));
    }

    public function test_an_unavailable_estimate_still_answers_in_the_shape_the_page_expects(): void
    {
        $this->rate();

        $response = $this->getJson('/lots/'.$this->lot(null)->id.'/freight-estimate?pickup=0')
            ->assertOk()
            ->json();

        // Same seven keys either way, so the page branches on `available` rather
        // than on which keys came back.
        $this->assertCount(7, $response);
        $this->assertSame(0, $response['totalCents']);
    }

    public function test_a_corridor_without_an_active_rate_is_reported_as_unavailable(): void
    {
        $this->rate(['active' => false]);

        $this->getJson('/lots/'.$this->lot(1800)->id.'/freight-estimate?pickup=1')
            ->assertOk()
            ->assertJson(['available' => false]);
    }

    public function test_a_lot_that_does_not_exist_is_still_a_missing_page(): void
    {
        $this->rate();

        // There is no figure to decline to quote, so this is an honest 404.
        $this->getJson('/lots/lot_nope/freight-estimate?pickup=0')->assertNotFound();
    }

    // -------------------------------------------------------------- read only

    public function test_asking_for_an_estimate_writes_nothing(): void
    {
        $this->rate();
        $lot = $this->lot(1800);

        $tables = ['lot', 'bid', 'wallet', 'ledger_entry', 'freight_rates', 'curb_weights'];

        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        // Asked twice, because the page fetches on load and again on toggle.
        $this->getJson('/lots/'.$lot->id.'/freight-estimate?pickup=0')->assertOk();
        $this->getJson('/lots/'.$lot->id.'/freight-estimate?pickup=1')->assertOk();

        foreach ($tables as $table) {
            $this->assertSame(
                $before[$table],
                DB::table($table)->count(),
                "asking for an estimate touched {$table}"
            );
        }
    }
}