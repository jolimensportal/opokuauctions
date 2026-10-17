<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single bid placed on a lot. Bids are immutable once written: corrections
 * happen by voiding, never by updating an amount.
 */
class Bid extends Model
{
    /** Singular on purpose: matches the Medusa spike schema exactly. */
    protected $table = 'bid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'lot_id', 'bidder_id', 'bidder_email', 'amount',
        'currency_code', 'status', 'caused_extension', 'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'integer',
        'caused_extension' => 'boolean',
    ];
}
