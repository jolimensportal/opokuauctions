<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demand capture for the sender quote page.
 *
 * The quote page prices a shipment but cannot book one yet, so this records
 * that a real person wanted to ship at a real price. That is the signal that
 * decides whether a booking flow is worth building.
 *
 * It is deliberately not a customer record. No password, no shipping address,
 * no identity: an email and what they asked to send. Everything else would be
 * data we have no need for and no consent to hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->enum('country', ['us', 'ca']);
            $table->string('postcode');
            $table->enum('unit', ['parcel', 'pallet'])->default('parcel');
            // What they were quoted, so we can tell whether demand clusters on
            // cheap parcels or expensive pallets.
            $table->integer('quoted_total_cents');
            $table->timestampsTz();

            $table->index('created_at');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_reservations');
    }
};
