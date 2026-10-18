<?php

namespace App\Models;

use App\Enums\IntakeMethod;
use App\Enums\ShipmentUnit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable price for one lane, unit and service.
 *
 * Never updated. Repricing sets effective_to on the old row and inserts a
 * successor, so a shipment booked in March is still explainable in October.
 */
class RateCardRow extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    // No timestamps. A row is never updated, so a modified-at column would
    // either always be null or actively lie about when the price changed.
    public $timestamps = false;

    protected $fillable = [
        'id', 'rate_card_id', 'origin_zone_id', 'destination', 'mode', 'unit',
        'intake_method', 'service_level', 'divisor', 'per_kg_cents',
        'per_pallet_cents', 'min_charge_cents', 'max_billable_kg',
        'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'unit' => ShipmentUnit::class,
            'intake_method' => IntakeMethod::class,
            'per_kg_cents' => 'integer',
            'per_pallet_cents' => 'integer',
            'min_charge_cents' => 'integer',
            'divisor' => 'integer',
            'max_billable_kg' => 'decimal:2',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
        ];
    }

    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class, 'rate_card_id');
    }

    public function originZone(): BelongsTo
    {
        return $this->belongsTo(OriginZone::class, 'origin_zone_id');
    }

    /**
     * Whether this row prices anything on a given day.
     *
     * Both bounds are inclusive. A window that silently excluded its own start
     * date would leave a lane unpriced for a day with nothing to show for it.
     */
    public function isEffectiveOn(CarbonImmutable $on): bool
    {
        if ($this->effective_from !== null && $on->lt($this->effective_from->startOfDay())) {
            return false;
        }

        if ($this->effective_to !== null && $on->gt($this->effective_to->endOfDay())) {
            return false;
        }

        return true;
    }

    /**
     * The currency this row prices in.
     *
     * Reachable from the row alone, because a stored quote records the row id
     * and has to be re-readable without loading the card separately.
     */
    public function currency(): string
    {
        return $this->rateCard?->currency ?? 'usd';
    }
}