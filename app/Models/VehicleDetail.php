<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The vehicle-only facts about a lot.
 *
 * Split out so `lot` stops requiring them: a pallet has no VIN and a bed frame
 * has no odometer, and the columns exist to filter a board by make.
 */
class VehicleDetail extends Model
{
    /** Singular on purpose, matching the existing lot / bid / wallet tables. */
    protected $table = 'vehicle_detail';

    /** No timestamps: the table has none, matching lot and shipment_lot. */
    public $timestamps = false;

    protected $fillable = [
        'lot_id', 'vin', 'year', 'make', 'model', 'trim', 'odometer', 'damage_primary',
    ];

    protected $casts = [
        'year' => 'integer',
        'odometer' => 'integer',
    ];

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }
}