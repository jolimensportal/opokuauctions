<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vehicle fields move off `lot` into `vehicle_detail`, so a pallet or a
     * single bed frame can be listed without inventing a make.
     *
     * The order is deliberate and must not be rearranged: add, create, copy,
     * then drop. At no point do both tables claim to hold the make, and at no
     * point is the source data gone before the copy has happened.
     */
    public function up(): void
    {
        // 1. Add the new shared columns. Nullable at first, because the
        //    backfill has to write into them before NOT NULL can be set.
        Schema::table('lot', function (Blueprint $table) {
            $table->string('kind')->nullable()->after('id');
            $table->string('provenance')->nullable()->after('kind');
            $table->text('description')->nullable()->after('city');
            $table->string('title')->nullable()->after('description');

            // "Curb" is vehicle vocabulary. A pallet has a weight too.
            $table->renameColumn('curb_weight_kg', 'weight_kg');
        });

        // 2. Every lot that exists today is a vehicle. Nothing is guessed.
        DB::table('lot')->update([
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => DB::raw("year || ' ' || make || ' ' || model"),
        ]);

        // 3. The detail tables.
        Schema::create('vehicle_detail', function (Blueprint $table) {
            $table->id();
            $table->string('lot_id')->unique();
            $table->string('vin')->nullable();
            $table->integer('year');
            $table->string('make');
            $table->string('model');
            $table->string('trim')->nullable();
            $table->integer('odometer')->nullable();
            $table->string('damage_primary')->nullable();

            $table->foreign('lot_id')->references('id')->on('lot')->cascadeOnDelete();
        });

        Schema::create('goods_detail', function (Blueprint $table) {
            $table->id();
            $table->string('lot_id')->unique();

            // "Pallet", "Bed frame", "Garage sale", "Truckload". This is what
            // separates a bulk lot from a single item without inventing a
            // third kind of lot.
            $table->string('unit_label');

            $table->string('grade')->nullable();
            $table->json('manifest')->nullable();
            $table->integer('manifest_count')->nullable();
            $table->integer('length_cm')->nullable();
            $table->integer('width_cm')->nullable();
            $table->integer('height_cm')->nullable();

            $table->foreign('lot_id')->references('id')->on('lot')->cascadeOnDelete();
        });

        // 4. Copy the vehicle columns across, then drop them.
        DB::statement(
            'insert into vehicle_detail (lot_id, vin, year, make, model, trim, odometer, damage_primary) '
            .'select id, vin, year, make, model, trim, odometer, damage_primary from lot'
        );

        Schema::table('lot', function (Blueprint $table) {
            $table->dropColumn(['vin', 'year', 'make', 'model', 'trim', 'odometer', 'damage_primary']);
        });

        // 5. The condition vocabulary becomes data, so the explanation under
        //    each condition can be edited without a deploy.
        Schema::create('condition_definition', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('code');
            $table->string('label');
            $table->text('blurb');
            $table->integer('sort')->default(0);

            $table->unique(['kind', 'code']);
        });

        DB::table('condition_definition')->insert([
            ['kind' => 'vehicle', 'code' => 'clean_title', 'label' => 'Clean title',
             'blurb' => 'No accident damage on record. Drives off the yard once it clears.', 'sort' => 1],
            ['kind' => 'vehicle', 'code' => 'salvage', 'label' => 'Salvage',
             'blurb' => 'Damage on record, usually repairable. We photograph the damage before you bid.', 'sort' => 2],
            ['kind' => 'vehicle', 'code' => 'project', 'label' => 'Project car',
             'blurb' => 'Runs, needs work. Budget for the repairs before you bid on it.', 'sort' => 3],
            ['kind' => 'vehicle', 'code' => 'parts_only', 'label' => 'Parts only',
             'blurb' => 'Not roadworthy. Sold for its parts.', 'sort' => 4],
            ['kind' => 'goods', 'code' => 'grade_a', 'label' => 'Grade A',
             'blurb' => 'New or like-new. Nothing wrong with it.', 'sort' => 1],
            ['kind' => 'goods', 'code' => 'grade_b', 'label' => 'Grade B',
             'blurb' => 'Used, works, shows wear. Nothing broken.', 'sort' => 2],
            ['kind' => 'goods', 'code' => 'returns', 'label' => 'Returns',
             'blurb' => 'Bought back or overstocked. Never sold at full price.', 'sort' => 3],
            ['kind' => 'goods', 'code' => 'damaged', 'label' => 'Damaged',
             'blurb' => 'We photograph the damage before you bid.', 'sort' => 4],
            ['kind' => 'goods', 'code' => 'mixed', 'label' => 'Mixed',
             'blurb' => 'A grab bag. What is in it is what you get.', 'sort' => 5],
        ]);

        // 6. Now that the copy is done and the columns are gone, the new
        //    columns can be required.
        Schema::table('lot', function (Blueprint $table) {
            $table->string('kind')->nullable(false)->change();
            $table->string('provenance')->nullable(false)->change();
            $table->string('title')->nullable(false)->change();
        });

        Schema::table('lot', function (Blueprint $table) {
            $table->index('kind');
            $table->index('provenance');
        });

        // A kind outside the two we support, or a provenance outside the
        // catalogue, would be a listing nothing can render. Blueprint has no
        // check() helper, so raw DDL, matching the existing chk_* convention.
        DB::statement(
            "alter table lot add constraint chk_lot_kind check (kind in ('vehicle', 'goods'))"
        );
        DB::statement(
            'alter table lot add constraint chk_lot_provenance check (provenance in ('
            ."'garage_sale', 'estate', 'repo', 'salvage_auction', 'liquidation', 'overstock', 'trade_in'"
            .'))'
        );
    }

    /**
     * Reversible, by copying the vehicle columns back out of vehicle_detail.
     *
     * The restored columns are nullable rather than NOT NULL: a goods lot has no
     * year or make, and a down() that cannot represent one has to relax the
     * constraint or refuse. Relaxing is chosen here because a refused down() on
     * a data migration strands anyone who needs to roll back before a release.
     * Lots that were goods keep NULLs, which is honest about where the data
     * came from.
     */
    public function down(): void
    {
        Schema::table('lot', function (Blueprint $table) {
            $table->string('vin')->nullable()->after('id');
            $table->integer('year')->nullable();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->string('trim')->nullable();
            $table->integer('odometer')->nullable();
            $table->string('damage_primary')->nullable();
        });

        // Copy back before the detail table goes.
        DB::statement(
            'update lot set '
            .'vin = v.vin, year = v.year, make = v.make, model = v.model, '
            .'trim = v.trim, odometer = v.odometer, damage_primary = v.damage_primary '
            .'from vehicle_detail v where v.lot_id = lot.id'
        );

        Schema::dropIfExists('condition_definition');
        Schema::dropIfExists('goods_detail');
        Schema::dropIfExists('vehicle_detail');

        Schema::table('lot', function (Blueprint $table) {
            $table->dropColumn(['kind', 'provenance', 'description', 'title']);
            $table->renameColumn('weight_kg', 'curb_weight_kg');
        });
    }
};