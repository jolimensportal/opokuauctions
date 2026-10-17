<?php

namespace App\Models;

use App\Services\CurbWeightLookup;
use Illuminate\Database\Eloquent\Model;

/**
 * A vehicle offered at auction on the OpokuAuctions corridor.
 *
 * Money is stored in integer minor units (cents) throughout. Never floats.
 */
class Lot extends Model
{
    /** Singular on purpose: matches the Medusa spike schema exactly. */
    protected $table = 'lot';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'vin', 'year', 'make', 'model', 'trim', 'city', 'photos',
        'condition', 'odometer', 'damage_primary', 'curb_weight_kg', 'currency_code',
        'starting_bid', 'reserve_price', 'bid_increment', 'deposit_bps',
        'balance_settled_at', 'balance_due_usd', 'fx_quote_id', 'starts_at', 'ends_at',
        'status', 'current_bid', 'highest_bidder_id', 'bid_count', 'extensions',
    ];

    protected $casts = [
        'photos' => 'array',
        'year' => 'integer',
        'starting_bid' => 'integer',
        'reserve_price' => 'integer',
        'bid_increment' => 'integer',
        'current_bid' => 'integer',
        'bid_count' => 'integer',
        'extensions' => 'integer',
        'deposit_bps' => 'integer',
        'curb_weight_kg' => 'float',
        'balance_settled_at' => 'immutable_datetime',
        'balance_due_usd' => 'integer',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        // A lot with a known make and model but no weight is a lot nobody can be
        // quoted freight for, so the weight is filled in on creation from the
        // vehicle lookup.
        //
        // This lives here rather than in the admin form because lots are also
        // created by the demo seeder, by imports and from a console. A default
        // that only the admin form applies is a default that half the fleet
        // quietly does without.
        //
        // A weight that is already set is never touched: someone measured that
        // car, or typed a number they know to be right.
        static::creating(function (self $lot) {
            if ($lot->curb_weight_kg !== null) {
                return;
            }

            $weight = app(CurbWeightLookup::class)->forVehicle(
                (string) $lot->make,
                (string) $lot->model,
            );

            if ($weight !== null) {
                $lot->curb_weight_kg = $weight;
            }
        });
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
