<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Tema customs checklist, per shipment.
 *
 * Two rules that shape this table:
 *
 * 1. **A tick with no receipt is not evidence.** Customs disputes are settled
 *    with documents, so a completed duty task without an attached receipt is
 *    recorded but explicitly flagged as unevidenced rather than quietly
 *    treated as done.
 *
 * 2. **Money-relevant tasks post their cost.** Duty, VAT and port charges are
 *    not just checklist items — they are the difference between what the buyer
 *    paid and what the platform actually spent. That difference is the landed
 *    cost, and it is the number the whole landed-cost promise rests on. Costs
 *    are integer minor units of USD, the settlement currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // Costs booked before the container even reaches Tema.
            $table->integer('freight_cost')->default(0)->after('tracking_source');
            $table->integer('insurance_cost')->default(0)->after('freight_cost');
        });

        Schema::create('clearance_tasks', function (Blueprint $table) {
            $table->string('id')->primary();

            $table->string('shipment_id');

            // See ClearanceTemplate for the ordered list of types.
            $table->string('type');
            $table->unsignedSmallInteger('position')->default(0);

            // pending | in_progress | complete | blocked
            $table->string('status')->default('pending');

            // Integer minor units of USD. Null for tasks that cost nothing.
            $table->integer('cost')->nullable();

            // A receipt is what makes a completed task evidence rather than a
            // claim. Nullable so an incomplete task can exist, but a completed
            // money task without one is flagged, not hidden.
            $table->string('receipt_path')->nullable();

            $table->text('notes')->nullable();

            $table->string('completed_by_user_id')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();

            // One task per type per shipment: the template is generated once.
            $table->unique(['shipment_id', 'type']);
            $table->index(['shipment_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clearance_tasks');

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['freight_cost', 'insurance_cost']);
        });
    }
};