<?php

namespace Tests\Feature;

use App\Models\CurbWeight;
use App\Models\FreightRate;
use App\Models\Lot;
use App\Services\CurbWeightLookup;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Working out how much a car weighs without weighing it.
 *
 * Freight is priced per kilo, so an unweighed car cannot be quoted, and a car
 * that cannot be quoted does not get bid on. The lookup table turns a make and
 * model into a curb weight so operations are not typing the same five numbers
 * for every Toyota Land Cruiser that crosses the Atlantic.
 *
 * Two limits are deliberate and tested here:
 *
 *  - **A suggestion, never an overwrite.** Operations know things the table
 *    does not: a modified suspension, a variant trim with a different kerb
 *    weight. A number someone typed by hand outranks a number we inferred.
 *
 *  - **Exact vehicle, no guessing at families.** "Land Cruiser" and "Land
 *    Cruiser Prado" weigh 200kg apart. A partial match that quietly returned
 *    the wrong one's weight would be worse than returning nothing, because it
 *    looks like an answer.
 *
 * The table is maintained by a seeder, not by an admin screen. It is reference
 * data, and reference data that an operator can edit one row of is reference
 * data that will disagree with itself by Friday.
 */
class CurbWeightLookupTest extends TestCase
{
    use RefreshDatabase;

    private CurbWeightLookup $weights;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weights = app(CurbWeightLookup::class);
    }

    private function vehicle(string $make, string $model, float $kg): CurbWeight
    {
        return CurbWeight::create([
            'make' => $make,
            'model' => $model,
            'weight_kg' => $kg,
        ]);
    }

    /**
     * A vehicle lot, with make and model written to vehicle_detail.
     *
     * Both moved in the multi-kind migration, so `curb_weight_kg` in an override
     * is translated rather than passed through. Accepting the old key is
     * deliberate: the tests below are about weight lookup, and renaming the
     * column in each assertion would obscure that.
     */
    private function lot(array $overrides = []): Lot
    {
        $detail = array_intersect_key($overrides, array_flip(['make', 'model']));
        $lotOverrides = array_diff_key($overrides, $detail);

        if (array_key_exists('curb_weight_kg', $lotOverrides)) {
            $lotOverrides['weight_kg'] = $lotOverrides['curb_weight_kg'];
            unset($lotOverrides['curb_weight_kg']);
        }

        // These tests are about weight lookup, so a helper lot must be a
        // weighable vehicle by default. Naming it explicitly means the factory
        // writes a matching vehicle_detail row, which is where the lookup reads
        // from now.
        $make = $detail['make'] ?? 'Toyota';
        $model = $detail['model'] ?? 'Land Cruiser';

        $lot = Lot::factory()->vehicle($make, $model)->create(array_merge([
            'id' => 'lot_'.uniqid(),
            'city' => 'Detroit',
            'starting_bid' => 100000,
            'reserve_price' => 500000,
            'bid_increment' => 25000,
            'deposit_bps' => 2000,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'status' => 'LIVE',
        ], $lotOverrides));

        if ($detail !== []) {
            $lot->vehicleDetail->update($detail);
        }

        return $lot->fresh();
    }

    // ------------------------------------------------------------- the lookup

    public function test_a_known_vehicle_yields_its_curb_weight(): void
    {
        $this->vehicle('Toyota', 'Land Cruiser', 2100);

        $this->assertSame(2100.0, $this->weights->forVehicle('Toyota', 'Land Cruiser'));
    }

    public function test_an_unknown_vehicle_yields_nothing(): void
    {
        $this->vehicle('Toyota', 'Land Cruiser', 2100);

        $this->assertNull($this->weights->forVehicle('Toyota', 'Hilux'));
    }

    public function test_an_empty_table_yields_nothing(): void
    {
        $this->assertNull($this->weights->forVehicle('Toyota', 'Land Cruiser'));
    }

    public function test_the_lookup_tolerates_the_capitals_and_spaces_a_clerk_types(): void
    {
        $this->vehicle('Toyota', 'Land Cruiser', 2100);

        // "honda" and "Honda" are the same car. Failing on a capital letter
        // means half the fleet has no weight and nobody notices until a lot
        // shows up unquotable.
        $this->assertSame(2100.0, $this->weights->forVehicle(' toyota ', 'land cruiser'));
    }

    public function test_a_trim_is_not_matched_against_its_bare_model_name(): void
    {
        $this->vehicle('Toyota', 'Land Cruiser', 2100);

        $this->assertNull(
            $this->weights->forVehicle('Toyota', 'Land Cruiser Prado'),
            'a variant that weighs 200kg more must not borrow the base model weight'
        );
    }

    // -------------------------------------------------------- filling a lot

    public function test_a_new_lot_of_a_known_vehicle_is_weighed_once_its_detail_exists(): void
    {
        $this->vehicle('Toyota', 'Land Cruiser', 2100);

        // Weighing used to happen in a `creating` hook that read make and model
        // straight off the lot. Those columns now live in vehicle_detail, which
        // has a foreign key to lot, so at creating time there is nothing to
        // read. Weighing is an explicit step now, and this asserts it works
        // once the vehicle it describes actually exists.
        $lot = $this->lot([
            'curb_weight_kg' => null,
            'make' => 'Toyota',
            'model' => 'Land Cruiser',
        ]);

        $this->assertSame(2100.0, $lot->fresh()->weight_kg);
    }

    /**
     * A lot whose detail row has not been written yet is left unweighed rather
     * than guessed at.
     *
     * This is the honest state of a vehicle lot mid-creation, and it is why
     * weigh-on-create is now an explicit call rather than a hook.
     */
    public function test_a_lot_with_no_vehicle_detail_is_left_unweighed(): void
    {
        $this->vehicle('Toyota', 'Land Cruiser', 2100);

        $lot = Lot::create([
            'id' => 'lot_'.uniqid(),
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => 'Toyota Land Cruiser',
            'city' => 'Detroit',
            'photos' => [],
            'starting_bid' => 100000,
            'reserve_price' => 500000,
            'bid_increment' => 25000,
            'deposit_bps' => 2000,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'status' => 'LIVE',
        ]);

        $this->assertNull($lot->fresh()->weight_kg);
        $this->assertNull($lot->fresh()->vehicleDetail);
    }

    public function test_a_goods_lot_is_never_weighed_from_the_vehicle_table(): void
    {
        $this->vehicle('Toyota', 'Land Cruiser', 2100);

        // A pallet has no make and model to look up. Reading the vehicle table
        // for one would be inventing a weight a buyer then gets billed for.
        $lot = Lot::factory()->goods('Pallet', 610.0)->create([
            'weight_kg' => null,
        ]);

        $this->assertNull($lot->fresh()->weight_kg);

        // And backfill skips it rather than counting it as unweighable.
        $result = $this->weights->backfill();

        $this->assertSame(0, $result['filled']);
        $this->assertSame(0, $result['unweighable']);
    }

    public function test_a_new_lot_with_a_measured_weight_keeps_it(): void
    {
        $this->vehicle('Toyota', 'Land Cruiser', 2100);

        $lot = $this->lot(['curb_weight_kg' => 1985]);

        $this->assertSame(1985.0, $lot->fresh()->weight_kg);
    }

    public function test_a_lot_weighed_after_the_fact_is_backfilled_by_apply_to(): void
    {
        // The vehicle was unknown when the lot arrived, which is how the table
        // catches up in the real world: someone adds a car we had not seen.
        $lot = $this->lot(['curb_weight_kg' => null]);
        $this->vehicle('Toyota', 'Land Cruiser', 2100);

        $this->weights->applyTo($lot);

        $this->assertSame(2100.0, $lot->fresh()->weight_kg);
    }

    public function test_a_lot_of_an_unknown_vehicle_is_left_unweighed(): void
    {
        $this->vehicle('Toyota', 'Land Cruiser', 2100);
        $lot = $this->lot(['model' => 'Some Chinese 7-seater', 'curb_weight_kg' => null]);

        $this->weights->applyTo($lot);

        $this->assertNull(
            $lot->fresh()->weight_kg,
            'an unknown vehicle stays unweighed rather than being given a guess'
        );
    }

    public function test_a_weight_operations_entered_by_hand_is_never_overwritten(): void
    {
        $this->vehicle('Toyota', 'Land Cruiser', 2100);
        $lot = $this->lot(['curb_weight_kg' => 1985]);

        $this->weights->applyTo($lot);

        // Modified springs, a different trim, a crane in the bed: operations
        // knows and the table does not.
        $this->assertSame(1985.0, $lot->fresh()->weight_kg);
    }

    public function test_applying_a_known_weight_leaves_the_rest_of_the_lot_alone(): void
    {
        $this->vehicle('Toyota', 'Land Cruiser', 2100);
        $lot = $this->lot(['curb_weight_kg' => null, 'status' => 'LIVE']);

        $this->weights->applyTo($lot);

        $fresh = $lot->fresh();
        $this->assertSame('LIVE', $fresh->status);
        $this->assertSame(2100.0, $fresh->weight_kg);
        $this->assertSame(100000, $fresh->starting_bid);
    }

    // -------------------------------------------------------- backfilling

    public function test_backfilling_weighs_what_it_can_and_reports_what_it_cannot(): void
    {
        // The lots exist before the table does, which is the real upgrade
        // order: these rows predate the weight column and will never be created
        // again, so nothing but an explicit pass will ever fill them.
        $weighed = $this->lot(['curb_weight_kg' => null]);
        $unknown = $this->lot(['model' => 'Some Chinese 7-seater', 'curb_weight_kg' => null]);
        $measured = $this->lot(['curb_weight_kg' => 1985]);

        $this->vehicle('Toyota', 'Land Cruiser', 2100);

        $result = $this->weights->backfill();

        // The counts are the point: operations needs to know how many lots are
        // still unquotable without going and counting rows by hand.
        $this->assertSame(['filled' => 1, 'unweighable' => 1], $result);
        $this->assertSame(2100.0, $weighed->fresh()->weight_kg);
        $this->assertNull($unknown->fresh()->weight_kg);
        $this->assertSame(1985.0, $measured->fresh()->weight_kg);
    }

    public function test_backfilling_an_empty_fleet_is_a_no_op(): void
    {
        $result = $this->weights->backfill();

        $this->assertSame(['filled' => 0, 'unweighable' => 0], $result);
    }

    // --------------------------------------------------------- into a quote

    public function test_a_lot_weighed_by_the_lookup_can_then_be_quoted(): void
    {
        $this->vehicle('Toyota', 'Land Cruiser', 2100);
        FreightRate::create([
            'lane' => 'na-tema',
            'usd_per_kg' => 0.32,
            'min_charge_cents' => 25000,
            'insurance_cents' => 10700,
            'pickup_cents' => 15000,
            'active' => true,
        ]);

        $lot = $this->lot(['curb_weight_kg' => null]);
        $this->weights->applyTo($lot);

        $quote = app(QuoteService::class)->forLot($lot->fresh(), false);

        $this->assertNotNull($quote);
        // 2100 kg x USD 0.32 = USD 672.00.
        $this->assertSame(67200, $quote->freightCents);
    }

    public function test_the_seeded_table_covers_the_demo_lots(): void
    {
        $this->seed(\Database\Seeders\CurbWeightSeeder::class);

        $lot = $this->lot(['make' => 'Toyota', 'model' => 'Land Cruiser', 'curb_weight_kg' => null]);

        $this->weights->applyTo($lot);

        $this->assertNotNull(
            $lot->fresh()->weight_kg,
            'a demo lot that ships with no weight shows buyers an empty freight card'
        );
    }
}