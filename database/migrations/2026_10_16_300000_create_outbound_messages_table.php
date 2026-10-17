<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound WhatsApp messages.
 *
 * Every message is written to this table before it is sent, and its outcome is
 * recorded afterwards. Three reasons:
 *
 *  1. **Audit.** A buyer can always be shown exactly what they were told and
 *     when. "The system said it arrives Tuesday" is answerable.
 *  2. **Retry.** Delivery fails. A queued row can be retried; a fire-and-forget
 *     call cannot.
 *  3. **Non-blocking.** The milestone moves regardless of what WhatsApp does.
 *     The container is at sea either way, and failing the shipment because a
 *     message did not send would be absurd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->string('id')->primary();

            $table->string('user_id');
            $table->string('phone');

            // What this message is about, for the audit trail.
            $table->string('channel')->default('whatsapp');
            $table->string('template')->nullable();

            // 'session' (free, inside the 24h service window) or 'template'
            // (pre-approved, may be sent any time).
            $table->string('message_kind')->default('session');

            $table->text('body');

            // Context that produced it: milestone, shipment, dispute.
            $table->string('about_type')->nullable();
            $table->string('about_id')->nullable();

            // queued | sending | sent | delivered | read | failed
            $table->string('status')->default('queued');

            $table->string('provider_message_id')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();

            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index('user_id');
            $table->index('about_type', 'about_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_messages');
    }
};