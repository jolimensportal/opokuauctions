<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a buyer still owes on a lot, in USD, and the rate that was locked when
 * they paid the deposit.
 *
 * Why this table exists rather than a float on the lot:
 *
 *  - The buyer's deposit is quoted in their own currency (GHS or CAD) but the
 *    platform settles with the forwarder in USD. Between those two numbers sits
 *    an exchange rate, and that rate moves. If we convert at the moment of
 *    display, a buyer can be shown a GHS figure and charged a different one a
 *    few hours later, which is exactly the accusation this whole product is
 *    built to be immune from.
 *
 *  - So the rate is written down once, at the moment the buyer commits, and
 *    stored. Everything the buyer is ever shown again derives from this row.
 *
 * The column is also the one Shipment::lotsAwaitingPayment() was already reading
 * (`balance_due_usd`) and which did not exist anywhere in the schema, so that
 * method threw on every call until this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot', function (Blueprint $table) {
            // Remaining balance in USD minor units. NULL means "nothing is owed
            // yet", which is different from zero.
            $table->integer('balance_due_usd')->nullable()->after('deposit_bps');
            $table->index('balance_due_usd');
        });

        Schema::create('fx_quotes', function (Blueprint $table) {
            $table->id();

            // Minor units per one unit of base. See the note below on why these
            // are integers and not decimals.
            $table->integer('base_minor');
            $table->integer('quote_minor');
            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);

            // The rate actually applied to this lot, stored as integers so it
            // round-trips exactly. USD/GHS quoted to 4 places.
            $table->integer('rate_numerator');
            $table->integer('rate_denominator');

            $table->timestampTz('locked_at');
            $table->timestampTz('expires_at')->nullable();

            $table->timestamps();

            $table->unique(['base_currency', 'quote_currency', 'locked_at'], 'fx_quotes_pair_locked_unique');
            $table->index(['base_currency', 'quote_currency', 'locked_at'], 'fx_quotes_pair_locked_index');
        });

        Schema::table('lot', function (Blueprint $table) {
            $table->foreignId('fx_quote_id')->nullable()->after('balance_due_usd')
                ->constrained('fx_quotes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lot', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fx_quote_id');
            $table->dropColumn('balance_due_usd');
        });

        Schema::dropIfExists('fx_quotes');
    }
};