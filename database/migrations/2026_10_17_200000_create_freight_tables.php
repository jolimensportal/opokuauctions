<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Weighing the cars, and pricing the voyage.
 *
 * Two small reference tables and one nullable column on `lot`, because freight
 * is the last thing a buyer is told and the first thing they ask about.
 *
 *  - `curb_weights` turns a make and model into a weight. Reference data, seeded
 *    and left alone: the moment operations can edit one row it will disagree
 *    with itself, and a wrong weight quotes a wrong price.
 *
 *  - `freight_rates` is the opposite: money operations must be able to correct.
 *    A forwarder raising a price is a routine occurrence, so the rate lives in
 *    the database where one edit reaches every quote, not in a config file
 *    behind a deploy.
 *
 * Weights are nullable on the lot on purpose. An unweighed car is a fact, not a
 * data-entry failure, and the quote says so rather than inventing a figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot', function (Blueprint $table) {
            // Nullable: a lot with no known weight gets no freight quote, and
            // that is a state the system has to be able to hold.
            $table->decimal('curb_weight_kg', 7, 1)->nullable()->after('damage_primary');
        });

        Schema::create('curb_weights', function (Blueprint $table) {
            $table->id();
            $table->string('make');
            $table->string('model');
            $table->decimal('weight_kg', 7, 1);
            $table->timestampsTz();

            $table->index('make');
        });

        // Uniqueness has to match how the lookup actually matches: trimmed and
        // case-insensitively. A plain unique(make, model) would let "Toyota" and
        // "toyota" both sit in the table, and the lookup would then have two
        // equally valid answers for one car.
        //
        // Laravel's Blueprint has no expression-index helper, so raw DDL.
        DB::statement(
            'create unique index curb_weights_vehicle_key on curb_weights (lower(btrim(make)), lower(btrim(model)))'
        );

        Schema::create('freight_rates', function (Blueprint $table) {
            $table->id();

            // One row per corridor, e.g. 'na-tema'. The lane is the business
            // key, not a description, so it is what the unique index is on.
            $table->string('lane')->unique();

            // USD per kilogram, as a decimal. The only non-integer column in the
            // pricing path, and it is multiplied into an integer once.
            $table->decimal('usd_per_kg', 8, 4);

            // Money in cents, like every other amount in this schema.
            $table->integer('min_charge_cents');
            $table->integer('insurance_cents');
            $table->integer('pickup_cents');

            // Retiring a rate stops it quoting. History is kept rather than
            // deleted: the figure a lot was quoted at last month is evidence.
            $table->boolean('active')->default(true);

            $table->timestampsTz();
        });

        // A negative charge is a typo that would credit the buyer.
        // Blueprint has no check() helper, so raw DDL.
        DB::statement(
            'alter table freight_rates add constraint chk_freight_rates_amounts_non_negative check ('
            .'usd_per_kg >= 0 and min_charge_cents >= 0 and insurance_cents >= 0 and pickup_cents >= 0'
            .')'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('freight_rates');
        Schema::dropIfExists('curb_weights');

        Schema::table('lot', function (Blueprint $table) {
            $table->dropColumn('curb_weight_kg');
        });
    }
};