<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One WhatsApp message, written before it is sent.
 *
 * This table is the audit trail and the retry queue. A message is only
 * "delivered" when the provider says so, and "sent" means we handed it over —
 * those are different claims and the column keeps them apart.
 */
class OutboundMessage extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'outbound_messages';

    protected $fillable = [
        'id', 'user_id', 'phone', 'channel', 'template', 'message_kind', 'body',
        'about_type', 'about_id', 'status', 'provider_message_id', 'last_error',
        'attempts', 'sent_at', 'delivered_at', 'failed_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'sent_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
    ];

    public function isTerminal(): bool
    {
        return in_array($this->status, ['delivered', 'read', 'failed'], true);
    }
}
