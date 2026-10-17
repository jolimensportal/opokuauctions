<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix a type mismatch: `shipment_lots.id` is a bigint (Laravel's `$table->id()`)
 * but `shipment_lot_statuses.shipment_lot_id` was declared as a string, so every
 * join between them failed with "operator does not exist: character varying =
 * bigint".
 *
 * Caught by the partial-delivery test, which is the only one that joins the
 * two tables. The gate and state-machine tests never touched this path, which
 * is a good argument for testing the join rather than only the writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_lot_statuses', function (Blueprint $table) {
            $table->dropColumn('shipment_lot_id');
        });

        Schema::table('shipment_lot_statuses', function (Blueprint $table) {
            $table->unsignedBigInteger('shipment_lot_id');
            $table->unique('shipment_lot_id');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_lot_statuses', function (Blueprint $table) {
            $table->dropUnique(['shipment_lot_id']);
            $table->dropColumn('shipment_lot_id');
        });

        Schema::table('shipment_lot_statuses', function (Blueprint $table) {
            $table->string('shipment_lot_id')->nullable();
        });
    }
};