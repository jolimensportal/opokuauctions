<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment in flight, before and after the provider confirms it.
 *
 * The row is created BEFORE the buyer is sent to Paystack or Stripe. That is
 * what allows a callback to be checked against something we actually asked
 * for, which is the first of four gates on the money-in path.
 */
class PaymentIntent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'payment_intents';

    protected $fillable = [
        'id', 'user_id', 'wallet_id', 'provider', 'amount', 'currency_code',
        'provider_reference', 'authorization_url', 'status', 'provider_event_id',
        'received_amount', 'received_currency', 'raw_payload', 'paid_at', 'failed_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'received_amount' => 'integer',
        'raw_payload' => 'array',
        'paid_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }

    public function isSettled(): bool
    {
        return $this->status === 'paid';
    }
}
