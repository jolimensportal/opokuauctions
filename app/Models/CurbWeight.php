<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How much a vehicle weighs, before anyone put a load in it.
 *
 * Reference data. It is seeded and deliberately has no admin screen: a lookup
 * table an operator can edit one row of is a lookup table that will disagree
 * with itself by the end of the week, and a wrong weight quietly quotes a wrong
 * price to a buyer.
 *
 * Real variance exists — a Land Cruiser and a Land Cruiser Prado differ by
 * around 200kg — which is why the match is on the exact vehicle and why the
 * lot itself keeps an overridable weight.
 */
class CurbWeight extends Model
{
    protected $fillable = ['make', 'model', 'weight_kg'];

    protected $casts = [
        // One decimal place, matching the column. A float cast would hand a
        // 2100.0 into the freight arithmetic where 2100 is meant.
        'weight_kg' => 'decimal:1',
    ];
}