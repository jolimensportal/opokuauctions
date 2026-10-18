<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reservation can be made from the refusal card.
 *
 * "We do not have a price for that lane yet" is a routine outcome, and the page
 * offers to email the customer when there is one. Those rows have no figure to
 * record, and forcing them to zero would put a fake price into the one table
 * whose entire job is telling us which lanes people actually want. Zero is a
 * price; this is the absence of one, and the column should say so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_reservations', function (Blueprint $table) {
            $table->unsignedInteger('quoted_total_cents')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('rate_reservations', function (Blueprint $table) {
            // Rows recorded without a price cannot be represented again.
            DB::table('rate_reservations')->whereNull('quoted_total_cents')->delete();

            $table->unsignedInteger('quoted_total_cents')->nullable(false)->change();
        });
    }
};