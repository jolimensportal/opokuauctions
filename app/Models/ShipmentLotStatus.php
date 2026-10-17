<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-lot state inside a shipment. One car held at customs must not stall the
 * other four in the same container, so exceptions are tracked here rather than
 * on the shipment.
 */
class ShipmentLotStatus extends Model
{
    protected $table = 'shipment_lot_statuses';

    protected $fillable = ['shipment_lot_id', 'status', 'note', 'resolved_at'];

    protected $casts = ['resolved_at' => 'immutable_datetime'];

    public function shipmentLot(): BelongsTo
    {
        return $this->belongsTo(ShipmentLot::class, 'shipment_lot_id');
    }
}
