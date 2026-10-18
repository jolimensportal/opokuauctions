<?php

namespace Tests\Feature;

use App\Models\Lot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The multi-kind migration moves vehicle columns off `lot` and into
 * `vehicle_detail`. If it drops a column before copying it, the make and model
 * of every car on the platform are gone and nothing in the test suite would
 * notice, because every other test builds its own rows after migrating.
 *
 * So this test does the only thing that can catch that: it rolls the migration
 * back, writes lots in the **old** shape, re-applies it, and checks they came
 * through intact.
 */
class MultiKindMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Put the database back into the pre-multi-kind shape, run $callback to
     * write old-style rows, then migrate forward again.
     *
     * @param  callable(): void  $callback
     */
    private function acrossMigration(callable $callback): void
    {
        // Roll back *this* migration by path, not by --step=1.
        //
        // Stepping back one migration only worked while this was the newest
        // file in the directory, so adding any later migration silently
        // turned every test in this file into a no-op that still passed its
        // assertions. Naming the path keeps this test testing this migration
        // no matter what lands after it.
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_10_17_300000_multi_kind_lots.php',
        ])->assertSuccessful();

        // The old shape has to exist for the rollback to have been real.
        $this->assertTrue(
            Schema::hasColumn('lot', 'make'),
            'Rollback did not restore the vehicle columns, so this test is not testing anything.',
        );

        $callback();

        $this->artisan('migrate')->assertSuccessful();
    }

    private function writeOldStyleLot(string $id, string $make, string $model, int $year): void
    {
        DB::table('lot')->insert([
            'id' => $id,
            'vin' => 'VIN'.$id,
            'year' => $year,
            'make' => $make,
            'model' => $model,
            'trim' => 'LE',
            'city' => 'Detroit',
            'photos' => '[]',
            'condition' => 'salvage',
            'odometer' => 88_000,
            'damage_primary' => 'front',
            'currency_code' => 'usd',
            'starting_bid' => 100_000,
            'reserve_price' => 150_000,
            'bid_increment' => 5_000,
            'current_bid' => 120_000,
            'bid_count' => 3,
            'extensions' => 0,
            'status' => 'LIVE',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
    }

    public function test_lots_written_before_the_migration_keep_their_vehicle_details(): void
    {
        $this->acrossMigration(function () {
            $this->writeOldStyleLot('lot_a', 'Toyota', 'Corolla', 2018);
            $this->writeOldStyleLot('lot_b', 'Honda', 'Civic', 2020);
        });

        $corolla = DB::table('vehicle_detail')->where('lot_id', 'lot_a')->first();

        $this->assertNotNull($corolla, 'lot_a lost its vehicle_detail row.');
        $this->assertSame('Toyota', $corolla->make);
        $this->assertSame('Corolla', $corolla->model);
        $this->assertSame(2018, (int) $corolla->year);
        $this->assertSame('LE', $corolla->trim);
        $this->assertSame(88_000, (int) $corolla->odometer);
        $this->assertSame('front', $corolla->damage_primary);
    }

    public function test_the_vehicle_columns_are_gone_from_lot_afterwards(): void
    {
        $this->acrossMigration(function () {
            $this->writeOldStyleLot('lot_a', 'Toyota', 'Corolla', 2018);
        });

        $this->assertFalse(
            Schema::hasColumn('lot', 'make'),
            'make should have moved into vehicle_detail.',
        );
        $this->assertFalse(Schema::hasColumn('lot', 'year'));
        $this->assertFalse(Schema::hasColumn('lot', 'model'));
    }

    public function test_a_backfilled_lot_is_kind_vehicle_with_a_readable_title(): void
    {
        $this->acrossMigration(function () {
            $this->writeOldStyleLot('lot_a', 'Toyota', 'Corolla', 2018);
        });

        $lot = Lot::find('lot_a');

        $this->assertNotNull($lot);
        $this->assertSame('vehicle', $lot->kind);
        $this->assertSame('2018 Toyota Corolla', $lot->title);
    }

    public function test_money_and_condition_survive_the_move(): void
    {
        $this->acrossMigration(function () {
            $this->writeOldStyleLot('lot_a', 'Toyota', 'Corolla', 2018);
        });

        $lot = Lot::find('lot_a');

        $this->assertSame(100_000, $lot->starting_bid);
        $this->assertSame(150_000, $lot->reserve_price);
        $this->assertSame(5_000, $lot->bid_increment);
        $this->assertSame(120_000, $lot->current_bid);
        $this->assertSame(3, $lot->bid_count);
        $this->assertSame(2000, $lot->deposit_bps, 'The deposit basis points must not move.');
        $this->assertSame('salvage', $lot->condition, 'Condition must survive even though its columns are now data.');
    }

    public function test_no_vehicle_is_left_without_a_detail_row(): void
    {
        $this->acrossMigration(function () {
            foreach (['lot_a' => 'Toyota', 'lot_b' => 'Honda', 'lot_c' => 'Ford'] as $id => $make) {
                $this->writeOldStyleLot($id, $make, 'Model', 2015);
            }
        });

        $vehicles = DB::table('lot')->where('kind', 'vehicle')->count();
        $details = DB::table('vehicle_detail')->count();

        $this->assertSame(3, $vehicles);
        $this->assertSame(3, $details, 'One detail row per vehicle, no more and no fewer.');
    }

    public function test_the_weight_column_is_renamed_rather_than_dropped(): void
    {
        $this->acrossMigration(function () {
            $this->writeOldStyleLot('lot_a', 'Toyota', 'Corolla', 2018);
            DB::table('lot')->where('id', 'lot_a')->update(['curb_weight_kg' => 1450.5]);
        });

        $this->assertTrue(Schema::hasColumn('lot', 'weight_kg'));
        $this->assertFalse(Schema::hasColumn('lot', 'curb_weight_kg'));

        $this->assertSame(1450.5, (float) Lot::find('lot_a')->weight_kg);
    }

    public function test_the_condition_vocabulary_is_available_as_data(): void
    {
        $this->acrossMigration(function () {
            $this->writeOldStyleLot('lot_a', 'Toyota', 'Corolla', 2018);
        });

        foreach (['clean_title', 'salvage', 'project', 'parts_only'] as $code) {
            $this->assertSame(
                1,
                DB::table('condition_definition')
                    ->where('kind', 'vehicle')->where('code', $code)->count(),
                "The vehicle condition '{$code}' must exist so existing lots keep a label.",
            );
        }

        $this->assertGreaterThan(
            0,
            DB::table('condition_definition')->where('kind', 'goods')->count(),
            'Goods lots need their own condition vocabulary.',
        );
    }

    public function test_a_goods_lot_needs_no_vehicle_detail(): void
    {
        $lot = Lot::create([
            'id' => 'lot_pallet',
            'kind' => 'goods',
            'provenance' => 'overstock',
            'title' => 'Pallet of 480 tissue cartons',
            'city' => 'Newark',
            'condition' => 'grade_a',
            'currency_code' => 'usd',
            'weight_kg' => 610.0,
            'starting_bid' => 60_000,
            'reserve_price' => 0,
            'bid_increment' => 5_000,
            'status' => 'LIVE',
            'current_bid' => 60_000,
            'bid_count' => 0,
            'extensions' => 0,
            'photos' => [],
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $this->assertSame('goods', $lot->kind);
        $this->assertNull($lot->vehicleDetail, 'A pallet must not need a vehicle detail row.');
    }
}