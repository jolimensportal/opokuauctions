<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The non-vehicle facts about a lot: what it is called, and — for bulk lots
 * only — the manifest that says what is inside it.
 *
 * One table serves a 480-carton pallet and a single bed frame. The difference
 * is how much of this is filled in, not a different kind of thing: the bulk
 * columns are simply null on a single item, because a headlamp has no carton
 * count and no dimensions worth recording.
 *
 * Dimensions get columns but do not feed the quote. Freight is weight-based
 * and volumetric pricing is a separate decision, deliberately not smuggled in
 * here.
 */
class GoodsDetail extends Model
{
    /** Singular on purpose, matching the existing lot / bid / wallet tables. */
    protected $table = 'goods_detail';

    /** No timestamps: the table has none, matching lot and shipment_lot. */
    public $timestamps = false;

    protected $fillable = [
        'lot_id', 'unit_label', 'grade', 'manifest', 'manifest_count',
        'length_cm', 'width_cm', 'height_cm',
    ];

    protected $casts = [
        'manifest' => 'array',
        'manifest_count' => 'integer',
        'length_cm' => 'integer',
        'width_cm' => 'integer',
        'height_cm' => 'integer',
    ];

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }
}