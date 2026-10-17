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

    private function lot(array $overrides = []): Lot
    {
        return Lot::create(array_merge([
            'id' => 'lot_'.uniqid(),
            'vin' => 'VIN-'.uniqid(),
            'year' => 2018,
            'make' => 'Toyota',
            'model' => 'Land Cruiser',
            'city' => 'Detroit',
            'photos' => [],
            'starting_bid' => 100000,
            'reserve_price' => 500000,
            'bid_increment' => 25000,
            'deposit_bps' => 2000,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'status' => 'LIVE',
        ], $overrides));
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

    public function test_a_new_lot_of_a_known_vehicle_is_weighed_on_creation(): void
    {
        $this->vehicle('Toyota', 'Land Cruiser', 2100);

        // Nothing asks for the weight; the lot is quotable the moment it exists.
        // Anywhere a lot is created should give the same answer, not just the
        // admin form: the seeder, an import script and a colleague's console all
        // create lots too.
        $lot = $this->lot(['curb_weight_kg' => null]);

        $this->assertSame(2100.0, $lot->fresh()->curb_weight_kg);
    }

    public function test_a_new_lot_with_a_measured_weight_keeps_it(): void
    {
        $this->vehicle('Toyota', 'Land Cruiser', 2100);

        $lot = $this->lot(['curb_weight_kg' => 1985]);

        $this->assertSame(1985.0, $lot->fresh()->curb_weight_kg);
    }

    public function test_a_lot_weighed_after_the_fact_is_backfilled_by_apply_to(): void
    {
        // The vehicle was unknown when the lot arrived, which is how the table
        // catches up in the real world: someone adds a car we had not seen.
        $lot = $this->lot(['curb_weight_kg' => null]);
        $this->vehicle('Toyota', 'Land Cruiser', 2100);

        $this->weights->applyTo($lot);

        $this->assertSame(2100.0, $lot->fresh()->curb_weight_kg);
    }

    public function test_a_lot_of_an_unknown_vehicle_is_left_unweighed(): void
    {
        $this->vehicle('Toyota', 'Land Cruiser', 2100);
        $lot = $this->lot(['model' => 'Some Chinese 7-seater', 'curb_weight_kg' => null]);

        $this->weights->applyTo($lot);

        $this->assertNull(
            $lot->fresh()->curb_weight_kg,
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
        $this->assertSame(1985.0, $lot->fresh()->curb_weight_kg);
    }

    public function test_applying_a_known_weight_leaves_the_rest_of_the_lot_alone(): void
    {
        $this->vehicle('Toyota', 'Land Cruiser', 2100);
        $lot = $this->lot(['curb_weight_kg' => null, 'status' => 'LIVE']);

        $this->weights->applyTo($lot);

        $fresh = $lot->fresh();
        $this->assertSame('LIVE', $fresh->status);
        $this->assertSame(2100.0, $fresh->curb_weight_kg);
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
        $this->assertSame(2100.0, $weighed->fresh()->curb_weight_kg);
        $this->assertNull($unknown->fresh()->curb_weight_kg);
        $this->assertSame(1985.0, $measured->fresh()->curb_weight_kg);
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
            $lot->fresh()->curb_weight_kg,
            'a demo lot that ships with no weight shows buyers an empty freight card'
        );
    }
}