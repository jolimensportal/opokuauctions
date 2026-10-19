<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What we actually asked the customer to pay, as distinct from what we quoted.
 *
 * The merchant account takes cedi and the quote is in USD, so a payment link is
 * raised at a converted figure. Recording only the quote would leave a GHS
 * charge with no trace of the rate it came from — and a payment we cannot
 * reconcile against a rate is a payment we cannot argue about later.
 *
 * charge_cents is in the CHARGE currency's smallest unit. For GHS that is
 * pesewas, so USD 25.00 at 15.05 is 376 pesewas and not 37.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_bookings', function (Blueprint $table) {
            $table->unsignedInteger('charge_cents')->nullable()->after('quoted_total_cents');
            $table->char('charge_currency', 3)->nullable()->after('charge_cents');
            $table->string('fx_rate', 32)->nullable()->after('charge_currency');
            $table->string('fx_quote_id', 36)->nullable()->after('fx_rate');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_bookings', function (Blueprint $table) {
            $table->dropColumn(['charge_cents', 'charge_currency', 'fx_rate', 'fx_quote_id']);
        });
    }
};
