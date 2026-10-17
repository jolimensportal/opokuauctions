<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only ledger. One row per balance movement, never updated, never
 * deleted. This table is the source of truth.
 */
class LedgerEntry extends Model
{
    /** Singular on purpose: matches the Medusa spike schema exactly. */
    protected $table = 'ledger_entry';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'wallet_id', 'user_id', 'currency_code', 'type', 'amount',
        'balance_after', 'reference_type', 'reference_id', 'note',
    ];

    /** The wallet this movement belongs to. Needed by the ledger's buyer filter. */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
    ];
}
