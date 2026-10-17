<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A buyer's balance in a single currency, in minor units (cents).
 *
 * Two buckets, never one:
 *   balance_available - spendable right now
 *   balance_held      - committed to winning bids, not spendable
 */
class Wallet extends Model
{
    /** Singular on purpose: matches the Medusa spike schema exactly. */
    protected $table = 'wallet';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'currency_code',
        'balance_available', 'balance_held', 'version',
    ];

    /** The append-only record behind these cached balances. */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'wallet_id');
    }

    protected $casts = [
        'balance_available' => 'integer',
        'balance_held' => 'integer',
        'version' => 'integer',
    ];
}
