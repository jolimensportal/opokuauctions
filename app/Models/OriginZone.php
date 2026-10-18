<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An internal pricing zone.
 *
 * Not customer-facing. The customer gives a postcode; the postcode maps to one
 * of these; rate rows price these. Naming zones after cities would put the
 * pricing decision in the name, and "US-NJ-NE" is not a rate.
 */
class OriginZone extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'country', 'code', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function bands(): HasMany
    {
        return $this->hasMany(PostcodeBand::class, 'zone_id');
    }

    public function rateRows(): HasMany
    {
        return $this->hasMany(RateCardRow::class, 'origin_zone_id');
    }
}