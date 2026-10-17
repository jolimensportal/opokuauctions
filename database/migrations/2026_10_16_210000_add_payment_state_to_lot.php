<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment state for a lot, used by the loading gate.
 *
 * `balance_settled_at` is the column the gate actually reads. It is set by the
 * money service when the balance is held, not by anyone typing a checkbox, so
 * "paid" cannot drift away from the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot', function (Blueprint $table) {
            // Basis points, not a float percentage. 2000 = 20%.
            $table->integer('deposit_bps')->default(2000)->after('bid_increment');

            // When the balance was held in the buyer's wallet.
            $table->timestampTz('balance_settled_at')->nullable()->after('deposit_bps');

            $table->index('balance_settled_at');
        });
    }

    public function down(): void
    {
        Schema::table('lot', function (Blueprint $table) {
            $table->dropColumn(['deposit_bps', 'balance_settled_at']);
        });
    }
};