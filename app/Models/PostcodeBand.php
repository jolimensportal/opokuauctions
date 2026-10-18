<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps the start of a normalised postcode to a zone.
 *
 * Resolution is longest-prefix-wins. "07" and "070" both match 07030, and
 * without a defined order the result depends on row order, which is not a rule
 * anyone can rely on or explain to a customer.
 */
class PostcodeBand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'country', 'prefix', 'zone_id', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(OriginZone::class, 'zone_id');
    }

    /**
     * The band covering a normalised postcode, or null.
     *
     * No fallback of any kind. A postcode with no band is an origin we have not
     * priced, and quoting a neighbouring rate is how a company loses money on a
     * lane it never sold.
     */
    public static function resolve(?string $normalised, string $country): ?self
    {
        if ($normalised === null || $normalised === '') {
            return null;
        }

        return self::query()
            ->where('country', $country)
            ->where('active', true)
            ->orderByRaw('char_length(prefix) DESC')
            ->get()
            ->first(fn (self $band) => str_starts_with($normalised, $band->prefix));
    }
}