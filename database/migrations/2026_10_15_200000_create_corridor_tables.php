<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema for the OpokuAuctions corridor.
 *
 * Identical to the Medusa spike on purpose, so the two stacks are compared on
 * behaviour rather than on schema differences.
 *
 * Money rules baked into the database, not just the PHP:
 *  - every amount is an integer in minor units (cents), never a float
 *  - a wallet can never hold a negative balance
 *  - one idempotency key can never produce two bids
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot', function (Blueprint $table) {
            $table->string('id')->primary();

            $table->string('vin')->nullable();
            $table->integer('year');
            $table->string('make');
            $table->string('model');
            $table->string('trim')->nullable();
            $table->string('city');
            $table->json('photos')->default('[]');
            $table->string('condition')->default('unknown');
            $table->integer('odometer')->nullable();
            $table->string('damage_primary')->nullable();

            $table->string('currency_code')->default('usd');
            $table->integer('starting_bid');
            $table->integer('reserve_price')->default(0);
            $table->integer('bid_increment');

            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');

            $table->string('status')->default('UPCOMING');
            $table->integer('current_bid')->default(0);
            $table->string('highest_bidder_id')->nullable();
            $table->integer('bid_count')->default(0);
            $table->integer('extensions')->default(0);

            $table->timestampsTz();

            $table->index('status');
            $table->index('ends_at');
        });

        Schema::create('bid', function (Blueprint $table) {
            $table->string('id')->primary();

            $table->string('lot_id');
            $table->string('bidder_id');
            $table->string('bidder_email');

            $table->integer('amount');
            $table->string('currency_code')->default('usd');
            $table->string('status')->default('ACCEPTED');
            $table->boolean('caused_extension')->default(false);
            $table->string('idempotency_key')->nullable();

            $table->timestampsTz();

            $table->index('lot_id');
            // A retried request must never create a second bid.
            $table->unique('idempotency_key');
        });

        Schema::create('wallet', function (Blueprint $table) {
            $table->string('id')->primary();

            $table->string('user_id');
            $table->string('currency_code');

            $table->integer('balance_available')->default(0);
            $table->integer('balance_held')->default(0);
            $table->integer('version')->default(1);

            $table->timestampsTz();

            $table->unique(['user_id', 'currency_code']);
        });

        // Money can never be negative. The service refuses to let it happen;
        // the database refuses too, so no future bug can mint debt.
        // Laravel's Blueprint has no check() helper, so this is raw DDL.
        DB::statement(
            'alter table wallet add constraint chk_wallet_available_non_negative check (balance_available >= 0)'
        );
        DB::statement(
            'alter table wallet add constraint chk_wallet_held_non_negative check (balance_held >= 0)'
        );

        Schema::create('ledger_entry', function (Blueprint $table) {
            $table->string('id')->primary();

            $table->string('wallet_id');
            $table->string('user_id');
            $table->string('currency_code');

            $table->string('type');
            $table->integer('amount');
            $table->integer('balance_after');

            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->text('note')->nullable();

            $table->timestampsTz();

            $table->index(['wallet_id', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entry');
        Schema::dropIfExists('wallet');
        Schema::dropIfExists('bid');
        Schema::dropIfExists('lot');
    }
};