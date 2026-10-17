<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded movement of a container.
 *
 * `source` matters: an admin record is never overwritten by a later API poll.
 * The admin knows what actually happened at the port; the tracking feed is a
 * third party that is frequently late or wrong.
 */
class ShipmentMilestone extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'shipment_milestones';

    protected $fillable = [
        'id', 'shipment_id', 'type', 'occurred_at', 'source',
        'recorded_by_user_id', 'notes',
    ];

    protected $casts = ['occurred_at' => 'immutable_datetime'];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }
}
