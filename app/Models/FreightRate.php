<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What it costs to move a kilo of car along one corridor.
 *
 * In the database rather than in config, because forwarders change prices and
 * a price change must not require a deploy. One row per lane, one active row
 * per lane: retiring a rate stops it quoting without erasing what a lot was
 * quoted last month, which is evidence.
 *
 * The per-kilo figure is a decimal because per-kilo figures are not round. The
 * money is cents, like every other amount in this schema.
 */
class FreightRate extends Model
{
    protected $fillable = [
        'lane', 'usd_per_kg', 'min_charge_cents',
        'insurance_cents', 'pickup_cents', 'active',
    ];

    protected $casts = [
        'usd_per_kg' => 'decimal:4',
        'min_charge_cents' => 'integer',
        'insurance_cents' => 'integer',
        'pickup_cents' => 'integer',
        'active' => 'boolean',
    ];
}