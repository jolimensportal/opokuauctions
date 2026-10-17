<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments in flight.
 *
 * This table exists to make one guarantee possible: **money is only ever
 * created by a verified provider callback that matches a payment we initiated.**
 * A webhook we never asked for has no row to match against, so it cannot
 * create value.
 *
 * Replay protection lives here as a unique index on the provider's event id.
 * An application-level "have I seen this?" check is not enough — two identical
 * webhooks arriving at the same moment both pass it.
 *
 * Amounts are integer minor units, per currency, exactly as in the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->string('id')->primary();

            $table->string('user_id');
            $table->string('wallet_id')->nullable();

            // paystack | stripe
            $table->string('provider');

            // What we asked the provider for, in the buyer's own currency.
            $table->integer('amount');
            $table->string('currency_code');

            // The provider's own reference for this payment, once known.
            $table->string('provider_reference')->nullable();
            $table->string('authorization_url')->nullable();

            // initiated | processing | paid | failed | abandoned
            $table->string('status')->default('initiated');

            // The provider's event id. Unique, so a replayed webhook cannot
            // mint a second deposit. This is the replay guard.
            $table->string('provider_event_id')->nullable()->unique();

            // What the provider said the amount was, in case it disagrees with
            // what we asked for. Recorded on mismatch so the discrepancy is
            // auditable rather than just rejected.
            $table->integer('received_amount')->nullable();
            $table->string('received_currency')->nullable();

            $table->json('raw_payload')->nullable();

            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('failed_at')->nullable();

            $table->timestampsTz();

            $table->index(['provider', 'provider_reference']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};