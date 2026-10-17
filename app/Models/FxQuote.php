<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rate frozen in time.
 *
 * One row is written when a buyer is quoted, and that row is the authority for
 * everything they are shown afterwards. It is never updated.
 */
class FxQuote extends Model
{
    protected $fillable = [
        'base_currency', 'quote_currency', 'base_minor', 'quote_minor',
        'rate_numerator', 'rate_denominator', 'locked_at', 'expires_at',
    ];

    protected $casts = [
        'base_minor' => 'integer',
        'quote_minor' => 'integer',
        'rate_numerator' => 'integer',
        'rate_denominator' => 'integer',
        'locked_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
    ];

    /** Lots quoted at this rate. */
    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class, 'fx_quote_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
