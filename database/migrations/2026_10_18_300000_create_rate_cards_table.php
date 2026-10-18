<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_cards', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            // Currency lives on the card, not the row, so one card is one
            // currency and a reprice in one can never touch another.
            $table->string('currency', 3);
            $table->timestampsTz();
        });

        Schema::create('rate_card_rows', function (Blueprint $table) {
            $table->string('id')->primary();
            // Strings, not foreignId(): both parents use string primary keys,
            // so a bigint column could never match the key it references.
            $table->string('rate_card_id');
            $table->foreign('rate_card_id')->references('id')->on('rate_cards')->cascadeOnDelete();

            $table->string('origin_zone_id');
            $table->foreign('origin_zone_id')->references('id')->on('origin_zones')->cascadeOnDelete();

            $table->string('destination');

            $table->enum('mode', ['air', 'sea']);
            $table->enum('unit', ['parcel', 'pallet']);
            // All three exist from day one so launching the third is a data
            // change, not a migration.
            $table->enum('intake_method', ['dropoff', 'partner_pickup', 'self_pickup']);
            $table->string('service_level');

            // Volumetric divisor: 2000 air, 5000 sea. On the row rather than in
            // code so a lane change is a data edit, not a deploy.
            $table->unsignedInteger('divisor');

            $table->integer('per_kg_cents');
            // NULL for a parcel. A pallet's floor, because a half-empty pallet
            // costs what a full one costs to handle.
            $table->integer('per_pallet_cents')->nullable();
            $table->integer('min_charge_cents')->default(0);
            $table->decimal('max_billable_kg', 8, 2)->nullable();

            // The effective window. Rows are never updated: repricing closes the
            // old row by setting effective_to and inserts a successor.
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // The lookup the resolver runs: one lane, one unit, one service.
            $table->index(
                ['origin_zone_id', 'destination', 'unit', 'intake_method', 'service_level'],
                'rate_card_rows_lookup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_card_rows');
        Schema::dropIfExists('rate_cards');
    }
};