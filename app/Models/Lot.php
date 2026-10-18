<?php

namespace App\Models;

use App\Services\CurbWeightLookup;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing offered at auction on the OpokuAuctions corridor.
 *
 * A lot can be a vehicle, a pallet, a liquidation bundle or a single garage
 * item — a headlamp at 13 cents is a lot with its own bid, not decoration
 * inside a bigger one. `kind` says which shape it is; `provenance` says where
 * it came from. The shape-specific facts live in `vehicle_detail` or
 * `goods_detail`, so this table holds only what a bid needs and therefore only
 * what the money and auction paths ever touch.
 *
 * Money is stored in integer minor units (cents) throughout. Never floats.
 */
class Lot extends Model
{
    use HasFactory;

    /** Singular on purpose: matches the Medusa spike schema exactly. */
    protected $table = 'lot';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'kind', 'provenance', 'title', 'description', 'city', 'photos',
        'condition', 'weight_kg', 'currency_code',
        'starting_bid', 'reserve_price', 'bid_increment', 'deposit_bps',
        'balance_settled_at', 'balance_due_usd', 'fx_quote_id', 'starts_at', 'ends_at',
        'status', 'current_bid', 'highest_bidder_id', 'bid_count', 'extensions',
    ];

    protected $casts = [
        'photos' => 'array',
        'starting_bid' => 'integer',
        'reserve_price' => 'integer',
        'bid_increment' => 'integer',
        'current_bid' => 'integer',
        'bid_count' => 'integer',
        'extensions' => 'integer',
        'deposit_bps' => 'integer',
        'weight_kg' => 'float',
        'balance_settled_at' => 'immutable_datetime',
        'balance_due_usd' => 'integer',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        // A lot's primary key is a string, not a sequence, and nothing has ever
        // generated one for a new record — the seeders hand-write lot_demo_01.
        //
        // Which means the admin create form could not create a lot at all: an
        // operator filling it in got a NOT NULL violation on lot.id. The id is
        // not something a person should be asked for, so it is minted here in
        // the house style rather than exposed as a field.
        static::creating(function (self $lot) {
            if (blank($lot->getKey())) {
                $lot->setAttribute($lot->getKeyName(), 'lot_'.Str::lower(Str::ulid()));
            }
        });
    }

    // NOTE: the automatic weigh-on-create that used to live in a `creating`
    // hook cannot work any more, and its absence is not an oversight.
    //
    // The hook read the lot's own `make` and `model`. Those columns now live in
    // `vehicle_detail`, which carries a foreign key to `lot` — so at `creating`
    // time the detail row does not exist yet and the hook would read nothing.
    //
    // Weighing is now an explicit step: `CurbWeightLookup::applyTo($lot)`. The
    // LotFactory calls it after the detail row is written, and anything else
    // that creates a vehicle lot must do the same. An unweighed lot is a lot
    // with no freight quote, which is a state the system already handles
    // honestly rather than a crash.

    /** Whether this lot is a car. One place, so a view never has to guess. */
    public function isVehicle(): bool
    {
        return $this->kind === 'vehicle';
    }

    /** The vehicle facts, for a car. Null on anything else. */
    public function vehicleDetail()
    {
        return $this->hasOne(VehicleDetail::class);
    }

    /** The goods facts, for a pallet, a bundle or a single item. */
    public function goodsDetail()
    {
        return $this->hasOne(GoodsDetail::class);
    }

    /** The rate this lot's buyer was quoted, frozen at deposit time. */
    public function fxQuote(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FxQuote::class, 'fx_quote_id');
    }

    /** Relation to the container carrying this lot, if it has one. */
    public function shipmentLot(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ShipmentLot::class, 'lot_id');
    }

    /**
     * The gate the loading step reads. Derived from the real payment state,
     * never from a flag someone can set by hand.
     */
    public function isBalanceSettled(): bool
    {
        return $this->balance_settled_at !== null;
    }

    /** Deposit as a fraction of the hammer price, e.g. 0.20 for 2000 bps. */
    public function depositFraction(): float
    {
        return $this->deposit_bps / 10000;
    }

    /**
     * The lowest amount that would be accepted as the next bid, in minor units.
     *
     * Lives here rather than in the service and the views because three places
     * need to agree on it: the bid validator, the amount the form pre-fills, and
     * the amount the page tells the buyer. If they ever drift, a buyer is told
     * they can bid an amount the server will reject.
     */
    public function minimumNextBid(): int
    {
        return $this->bid_count === 0
            ? $this->starting_bid
            : $this->current_bid + $this->bid_increment;
    }

    /**
     * The photo a listing leads with, or null when a lot was imported without
     * imagery. Views must handle null rather than assuming a URL: a lot with no
     * photo is data we will hit, not a bug.
     */
    public function primaryPhoto(): ?string
    {
        $photos = $this->photos;

        return is_array($photos) && count($photos) > 0 ? $photos[0] : null;
    }

    /**
     * How long until this lot closes, in words a person would use.
     *
     * Deliberately coarse: "2 days 4 hrs" is what someone deciding whether to bid
     * needs. A precise minutes figure on a two-day auction reads as noise and
     * gets stale the moment the page is loaded.
     */
    public function timeUntilClose(): string
    {
        if (! $this->ends_at) {
            return 'Closing soon';
        }

        $seconds = now()->diffInSeconds($this->ends_at, false);

        if ($seconds <= 0) {
            return 'Closing';
        }

        // Round to whole units rather than truncating, so a countdown never
        // understates the time a buyer actually has.
        //
        // The order below matters. Rounding days and hours separately is what
        // produced "4 days 24 hr": 4d23h59m rounds days to 5 and hours to 24.
        // Total hours are rounded once, then decomposed, so the hour remainder
        // is always 0-23 by construction.
        if ($seconds < 3600) {
            return max(1, (int) round($seconds / 60)).' min';
        }

        $totalHours = (int) round($seconds / 3600);
        $days = intdiv($totalHours, 24);
        $hours = $totalHours % 24;

        // Minutes are only useful when a buyer can plausibly act on them. Once a
        // lot is more than six hours out, "19 hr" is the honest and quieter answer.
        if ($days === 0 && $totalHours < 6) {
            return $totalHours.' hr '.(int) round(($seconds % 3600) / 60).' min';
        }

        if ($days === 0) {
            return $totalHours.' hr';
        }

        return $days.' day'.($days === 1 ? '' : 's').($hours > 0 ? ' '.$hours.' hr' : '');
    }

    /** True when a lot is open and a buyer can still act on it. */
    public function isAcceptingBids(): bool
    {
        return $this->status === 'LIVE'
            && $this->ends_at !== null
            && $this->ends_at->isFuture();
    }
}
