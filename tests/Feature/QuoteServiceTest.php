<?php

namespace Tests\Feature;

use App\Models\FreightRate;
use App\Models\Lot;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Freight is a container cost divided between the lots aboard it.
 *
 * The old rule charged every lot a $250 minimum consignment charge and $107 of
 * flat insurance. That is right for a car and absurd for a 13-cent headlamp, so
 * the minimum is applied once per container and the shares are divided by
 * weight.
 */
class QuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private function lot(float $weightKg, string $id = 'lot_a'): Lot
    {
        return Lot::create([
            'id' => $id,
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => '2018 Toyota Corolla',
            'city' => 'Detroit',
            'condition' => 'clean_title',
            'currency_code' => 'usd',
            'weight_kg' => $weightKg,
            'starting_bid' => 100_000,
            'reserve_price' => 150_000,
            'bid_increment' => 5_000,
            'status' => 'UPCOMING',
            'current_bid' => 0,
            'bid_count' => 0,
            'extensions' => 0,
            'photos' => [],
            'starts_at' => now(),
            'ends_at' => now()->addDay(),
        ]);
    }

    private function rate(array $overrides = []): FreightRate
    {
        return FreightRate::create(array_merge([
            'lane' => QuoteService::LANE,
            'usd_per_kg' => 0.3200,
            'min_charge_cents' => 25_000,
            'insurance_cents' => 10_700,
            'pickup_cents' => 15_000,
            'active' => true,
        ], $overrides));
    }

    /** Put lots aboard one container. */
    private function container(array $lots): Shipment
    {
        $shipment = Shipment::create([
            'id' => 'shp_'.uniqid(),
            'status' => 'PLANNED',
            'freight_cost' => 0,
            'insurance_cost' => 0,
        ]);

        foreach ($lots as $lot) {
            ShipmentLot::create([
                'shipment_id' => $shipment->id,
                'lot_id' => $lot->id,
                'quantity' => 1,
            ]);
        }

        return $shipment;
    }

    public function test_a_lot_with_no_known_weight_gets_no_quote(): void
    {
        $this->rate();
        $lot = $this->lot(0.0);
        $this->container([$lot]);

        $this->assertNull((new QuoteService)->forLot($lot));
    }

    public function test_an_unpriced_corridor_gets_no_quote(): void
    {
        $lot = $this->lot(1800.0);

        $this->assertNull((new QuoteService)->forLot($lot));
    }

    public function test_a_lot_not_yet_on_a_container_gets_no_quote(): void
    {
        $this->rate();

        $this->assertNull((new QuoteService)->forLot($this->lot(1800.0)));
    }

    public function test_the_minimum_charge_is_applied_once_per_container_not_per_lot(): void
    {
        // 45.2 kg at $0.32/kg is $14.46, far below the $250 floor, so the floor
        // IS this container's freight. It is then divided once between the two
        // lots. Under the old rule each lot would have been charged the whole
        // $250 — $500 to move $14.46 of freight.
        $this->rate(['min_charge_cents' => 25_000, 'insurance_cents' => 0]);
        $light = $this->lot(5.2, 'lot_light');
        $heavy = $this->lot(40.0, 'lot_heavy');

        $this->container([$light, $heavy]);

        $service = new QuoteService;

        $lightQuote = $service->forLot($light);
        $heavyQuote = $service->forLot($heavy);

        $this->assertNotNull($lightQuote);
        $this->assertNotNull($heavyQuote);

        // Neither lot carries the whole $250; it was applied once and split.
        $this->assertLessThan(25_000, $lightQuote->freightCents);
        $this->assertLessThan(25_000, $heavyQuote->freightCents);

        // And the shares reconcile to the container's floored freight.
        $this->assertSame(
            25_000,
            $lightQuote->freightCents + $heavyQuote->freightCents,
        );
    }

    public function test_a_container_above_the_floor_charges_real_freight_not_the_minimum(): void
    {
        // The floor is a minimum, not a price. A container genuinely costing
        // more than $250 must be charged the real amount, and the largest lot
        // may legitimately carry more than the floor on its own.
        $this->rate(['min_charge_cents' => 25_000, 'insurance_cents' => 0]);
        $heavy = $this->lot(1800.0, 'lot_heavy');

        $this->container([$heavy]);

        $quote = (new QuoteService)->forLot($heavy);

        // 1,800 kg at $0.32/kg = $576.00.
        $this->assertSame(57_600, $quote->freightCents);
    }

    public function test_shares_are_proportional_to_weight(): void
    {
        // 1:3 split of a container whose raw freight is under the minimum, so
        // the floor decides the total and weight decides the split.
        $this->rate(['min_charge_cents' => 40_000, 'insurance_cents' => 0]);
        $light = $this->lot(10.0, 'lot_light');
        $heavy = $this->lot(30.0, 'lot_heavy');

        $this->container([$light, $heavy]);

        $service = new QuoteService;

        $this->assertSame(10_000, $service->forLot($light)->freightCents);
        $this->assertSame(30_000, $service->forLot($heavy)->freightCents);
    }

    public function test_insurance_is_shared_rather_than_charged_flat_per_lot(): void
    {
        $this->rate(['insurance_cents' => 10_700]);
        $one = $this->lot(1800.0, 'lot_one');
        $two = $this->lot(1800.0, 'lot_two');

        $this->container([$one, $two]);

        $service = new QuoteService;

        $this->assertSame(5_350, $service->forLot($one)->insuranceCents);
        $this->assertSame(5_350, $service->forLot($two)->insuranceCents);
    }

    public function test_the_shares_of_a_shipment_sum_to_what_was_booked(): void
    {
        // 2,045.2 kg at $0.32/kg = $654.46 = 65,446 cents, which clears the
        // 50,555 minimum, so the floor does not apply and the shares must
        // reconcile to the real container cost with no cent lost or invented.
        //
        // The plan expected 6,544,640 here, which is a hundred times too high:
        // it mistook the intermediate grams x rate product for cents.
        $this->rate(['min_charge_cents' => 50_555, 'insurance_cents' => 10_733]);
        $lots = [
            $this->lot(5.2, 'lot_a'),
            $this->lot(1800.0, 'lot_b'),
            $this->lot(240.0, 'lot_c'),
        ];

        $this->container($lots);

        $service = new QuoteService;

        $freight = 0;
        $insurance = 0;

        foreach ($lots as $lot) {
            $quote = $service->forLot($lot);
            $freight += $quote->freightCents;
            $insurance += $quote->insuranceCents;
        }

        $this->assertSame(
            65_446,
            $freight,
            'Freight shares must sum to the container cost, to the cent.',
        );
        $this->assertSame(
            10_733,
            $insurance,
            'Insurance shares must sum back to the insurance booked.',
        );
    }

    public function test_pickup_is_charged_only_when_the_buyer_asks_for_it(): void
    {
        $this->rate();
        $lot = $this->lot(1800.0);

        $this->container([$lot]);

        $service = new QuoteService;

        $this->assertSame(0, $service->forLot($lot, false)->pickupCents);
        $this->assertSame(15_000, $service->forLot($lot, true)->pickupCents);
    }

    public function test_raising_the_rate_raises_the_quote(): void
    {
        $rate = $this->rate(['min_charge_cents' => 0, 'insurance_cents' => 0]);
        $lot = $this->lot(1800.0);

        $this->container([$lot]);

        $service = new QuoteService;
        $before = $service->forLot($lot)->freightCents;

        $rate->update(['usd_per_kg' => 0.4000]);

        $this->assertGreaterThan($before, $service->forLot($lot)->freightCents);
    }
}