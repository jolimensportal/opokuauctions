<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One container carrying many won lots across the corridor.
 */
class Shipment extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'shipments';

    protected $fillable = [
        'id', 'forwarder_id', 'vessel', 'voyage', 'container_no', 'booking_ref',
        'bill_of_lading', 'seaport_origin', 'seaport_destination', 'status',
        'freight_cost', 'insurance_cost',
        'eta', 'actual_departure', 'actual_arrival', 'tracking_source',
    ];

    protected $casts = [
        'eta' => 'immutable_datetime',
        'actual_departure' => 'immutable_datetime',
        'actual_arrival' => 'immutable_datetime',
        'freight_cost' => 'integer',
        'insurance_cost' => 'integer',
    ];

    public function lots(): HasMany
    {
        return $this->hasMany(ShipmentLot::class, 'shipment_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ShipmentMilestone::class, 'shipment_id');
    }

    public function forwarder(): BelongsTo
    {
        return $this->belongsTo(Forwarder::class, 'forwarder_id');
    }

    /**
     * Lots on this shipment whose balance is still outstanding.
     *
     * The whereHas closure receives a query Builder for the related model, not
     * an instance of it. Writing `$lot->balance_due_usd > 0` there sets a
     * dynamic property on the Builder and throws, so this must be a where()
     * against the related table's column.
     */
    public function lotsAwaitingPayment(): HasMany
    {
        return $this->lots()->whereHas(
            'lot',
            fn ($query) => $query->where('balance_due_usd', '>', 0),
        );
    }
}
