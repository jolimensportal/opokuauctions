<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The corridor. Multiple won lots move in one container, so this is what makes
 * the product a shipping platform rather than an auction site.
 *
 * Two rules are enforced here at the schema level because they must not be
 * bypassable from application code:
 *  - a lot can only be allocated to one shipment at a time
 *  - money is never stored on a shipment; it lives on wallets and the ledger
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forwarders', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->timestampsTz();
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->string('id')->primary();

            $table->string('forwarder_id')->nullable();
            $table->string('vessel')->nullable();
            $table->string('voyage')->nullable();

            // ISO 6346 container number, the key every tracking API needs.
            $table->string('container_no')->nullable()->unique();
            $table->string('booking_ref')->nullable();
            $table->string('bill_of_lading')->nullable();

            $table->string('seaport_origin')->nullable();
            $table->string('seaport_destination')->nullable();

            $table->string('status')->default('BOOKING');

            $table->timestampTz('eta')->nullable();
            $table->timestampTz('actual_departure')->nullable();
            $table->timestampTz('actual_arrival')->nullable();

            // shipsgo | traqo | manual
            $table->string('tracking_source')->nullable();

            $table->timestampsTz();

            $table->index('status');
            $table->index('eta');
        });

        Schema::create('shipment_lots', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_id');
            $table->string('lot_id');
            $table->integer('quantity')->default(1);
            $table->timestampsTz();

            $table->unique(['shipment_id', 'lot_id']);
            $table->index('lot_id');

            // One container per lot. Without this a lot could be allocated
            // twice and the platform would be promising the same car to two
            // buyers.
            $table->unique('lot_id');
        });

        Schema::create('shipment_milestones', function (Blueprint $table) {
            $table->string('id')->primary();

            $table->string('shipment_id');

            // BOOKED | LOADED | SAILED | ARRIVED | DISCHARGED | GATE_OUT |
            // CLEARED | DELIVERED | ROLLED_OVER | DAMAGED | WRITTEN_OFF
            $table->string('type');

            $table->timestampTz('occurred_at');

            // api | admin. An admin record is never overwritten by a later
            // API poll: tracking feeds are late and wrong, and telling a buyer
            // the wrong arrival date is a support incident, not a data issue.
            $table->string('source')->default('api');

            $table->string('recorded_by_user_id')->nullable();
            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->index(['shipment_id', 'type']);
        });

        // Lot-level state inside a shipment, so one car held at customs does
        // not stall the other four in the same container.
        Schema::create('shipment_lot_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_lot_id');

            // ALLOCATED | LOADED | IN_TRANSIT | CLEARED | GATE_OUT |
            // DELIVERED | HELD | DAMAGED | WRITTEN_OFF
            $table->string('status')->default('ALLOCATED');
            $table->text('note')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->unique('shipment_lot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_lot_statuses');
        Schema::dropIfExists('shipment_milestones');
        Schema::dropIfExists('shipment_lots');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('forwarders');
    }
};