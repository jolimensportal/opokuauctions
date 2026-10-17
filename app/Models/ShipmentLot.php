<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The join between a won lot and the container carrying it. This is what makes
 * consolidation possible: five buyers' cars from Detroit, one booking, one
 * freight bill.
 */
class ShipmentLot extends Model
{
    protected $table = 'shipment_lots';

    protected $fillable = ['shipment_id', 'lot_id', 'quantity'];

    protected $casts = ['quantity' => 'integer'];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'lot_id');
    }

    public function statusRecord(): BelongsTo
    {
        return $this->hasOne(ShipmentLotStatus::class, 'shipment_lot_id');
    }
}
