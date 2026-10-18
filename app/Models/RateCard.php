<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named group of rate rows in one currency.
 *
 * Carries no dates. The effective window lives on the row, because two tables
 * holding "when does this price apply" is two sources of truth.
 */
class RateCard extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'currency'];

    public function rows(): HasMany
    {
        return $this->hasMany(RateCardRow::class, 'rate_card_id');
    }
}