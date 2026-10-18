<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The only piece of state the operations console keeps.
 *
 * An exception is a query rather than a row, so this table exists purely to
 * answer "who owns this one". The key is (kind, ref) and not a surrogate id:
 * two exceptions are the same exception exactly when they describe the same
 * underlying row, and a natural key makes that identity checkable in the
 * database instead of only in application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attention_assignments', function (Blueprint $table) {
            $table->enum('kind', [
                'unevidenced_money',
                'shipment_uncleared',
                'shipment_unallocated',
                'open_lot_exception',
                'failed_message',
                'lot_unweighed',
                'wallet_held',
            ]);

            $table->string('ref');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('assigned_at')->useCurrent();

            $table->primary(['kind', 'ref']);
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attention_assignments');
    }
};