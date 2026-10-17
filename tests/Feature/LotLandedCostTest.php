<?php

namespace Tests\Feature;

use App\Models\ClearanceTask;
use App\Models\Lot;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The lot page must answer the question a Tema buyer actually asks: what does
 * this car cost me all-in at the port? The numbers come from the clearance
 * ledger — freight, insurance, duty, VAT and port charges the platform has
 * actually booked — never from a figure typed into a template. A lot that has
 * not been allocated to a container has no landed cost yet, and the page says
 * nothing rather than guessing.
 */
class LotLandedCostTest extends TestCase
{
    use RefreshDatabase;

    /** A unique live lot. Columns every test leaves alone are defaulted so
     *  each test states only its own column. */
    private function lot(array $overrides = []): Lot
    {
        $n = (string) substr(md5(json_encode($overrides).microtime()), 0, 10);

        return Lot::create(array_merge([
            'id' => 'lot_test_'.$n,
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => 'Toyota Corolla',
            'city' => 'Detroit',
            'condition' => 'clean_title',
            'currency_code' => 'usd',
            'starting_bid' => 100_000,
            'reserve_price' => 150_000,
            'bid_increment' => 5_000,
            'status' => 'LIVE',
            'current_bid' => 100_000,
            'bid_count' => 0,
            'extensions' => 0,
            'starts_at' => now()->subDay(),
            'photos' => [],
            'ends_at' => now()->addHour(),
        ], $overrides));
    }

    /**
     * Put the lot on a container with booked corridor costs. No factories
     * exist for these models, so the rows are written directly.
     */
    private function allocate(
        Lot $lot,
        array $taskCosts = [],
        int $freight = 385_000,
        int $insurance = 27_500,
        int $quantity = 1,
    ): Shipment {
        $n = (string) substr(md5($lot->id.microtime()), 0, 10);

        $shipment = Shipment::create([
            'id' => 'shp_test_'.$n,
            'status' => 'IN_TRANSIT',
            'freight_cost' => $freight,
            'insurance_cost' => $insurance,
        ]);

        ShipmentLot::create([
            'shipment_id' => $shipment->id,
            'lot_id' => $lot->id,
            'quantity' => $quantity,
        ]);

        foreach ($taskCosts as $type => $cost) {
            ClearanceTask::create([
                'id' => 'clt_test_'.$n.'_'.$type,
                'shipment_id' => $shipment->id,
                'type' => $type,
                'status' => 'complete',
                'cost' => $cost,
            ]);
        }

        return $shipment;
    }

    public function test_an_allocated_lot_shows_the_itemised_landed_cost_from_the_clearance_ledger(): void
    {
        $lot = $this->lot();
        $this->allocate($lot, [
            'DUTY_PAID' => 42_000,
            'VAT_PAID' => 33_000,
            'PORT_CHARGES' => 18_000,
        ]);
        // freight 3,850.00 + insurance 275.00 + tasks 930.00 = 5,055.00 booked
        // for the container; one vehicle in it takes the whole share; the lot
        // itself was won at 1,000.00, so it lands at 6,055.00.

        $response = $this->get('/lots/'.$lot->id);

        $response->assertOk();
        $response->assertSee('Landed cost');
        $response->assertSee('3,850.00');
        $response->assertSee('275.00');
        $response->assertSee('930.00');
        $response->assertSee('5,055.00');
        $response->assertSee('6,055.00');
        $response->assertSee('share of freight, insurance and clearance');
    }

    public function test_a_vehicle_shares_the_container_costs_across_every_vehicle_in_it(): void
    {
        $lot = $this->lot();
        $other = $this->lot();
        $shipment = $this->allocate($lot, [
            'DUTY_PAID' => 42_000,
            'VAT_PAID' => 33_000,
            'PORT_CHARGES' => 18_000,
        ]);
        ShipmentLot::create([
            'shipment_id' => $shipment->id,
            'lot_id' => $other->id,
            'quantity' => 1,
        ]);
        // 5,055.00 booked ÷ 2 vehicles = 2,527.50 each; won at 1,000.00, so
        // this lot lands at 3,527.50.

        $response = $this->get('/lots/'.$lot->id);

        $response->assertOk();
        $response->assertSee('2,527.50');
        $response->assertSee('3,527.50');
        $response->assertSee('divided across 2 vehicles');
    }

    public function test_a_lot_not_yet_allocated_has_no_landed_cost_section(): void
    {
        $lot = $this->lot();

        $response = $this->get('/lots/'.$lot->id);

        $response->assertOk();
        $response->assertDontSee('Landed cost');
    }
}
