<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\ClearanceService;

/**
 * One line of the Tema customs checklist.
 *
 * `cost` and `receipt_path` are the two fields that make this more than a
 * to-do list: one records what was spent, the other proves it.
 */
class ClearanceTask extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'clearance_tasks';

    protected $fillable = [
        'id', 'shipment_id', 'type', 'position', 'status', 'cost',
        'receipt_path', 'notes', 'completed_by_user_id', 'completed_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'cost' => 'integer',
        'completed_at' => 'immutable_datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }

    /** Complete, cost money, and carry no receipt. A problem waiting to happen. */
    public function isUnevidenced(): bool
    {
        return $this->isComplete()
            && ClearanceService::costsMoney($this->type)
            && blank($this->receipt_path);
    }
}
