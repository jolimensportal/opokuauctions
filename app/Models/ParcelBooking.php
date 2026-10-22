<?php

namespace App\Models;

use App\Enums\ParcelBookingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One parcel somebody asked to book.
 *
 * Holds enough of the original enquiry to re-price the shipment from nothing
 * but this row. That is deliberate: a booking that can only be honoured by
 * trusting a total stored in somebody's browser is not a booking.
 */
class ParcelBooking extends Model
{
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'reference', 'status', 'email', 'user_id', 'whatsapp_to', 'country', 'postcode', 'unit',
        'weight_grams', 'length_cm', 'width_cm', 'height_cm', 'pickup',
        'quoted_total_cents', 'charge_cents', 'charge_currency', 'fx_rate', 'fx_quote_id', 'rate_card_row_id', 'quote_input', 'payment_link_url',
        'expires_at', 'abandoned_at', 'booked_at',
    ];

    protected $casts = [
        'status' => ParcelBookingStatus::class,
        'pickup' => 'boolean',
        'weight_grams' => 'integer',
        'length_cm' => 'integer',
        'width_cm' => 'integer',
        'height_cm' => 'integer',
        'quoted_total_cents' => 'integer',
        'charge_cents' => 'integer',
        'quote_input' => 'array',
        'expires_at' => 'immutable_datetime',
        'abandoned_at' => 'immutable_datetime',
        'booked_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        // A string primary key with nothing to generate it means an insert
        // fails on NOT NULL, which is the same trap the lot table had.
        static::creating(function (self $booking) {
            if (blank($booking->getKey())) {
                $booking->setAttribute($booking->getKeyName(), 'bk_'.Str::lower(Str::ulid()));
            }

            if (blank($booking->reference)) {
                $booking->setAttribute('reference', Str::lower(Str::random(16)));
            }
        });
    }

    /**
     * The buyer who sent this parcel, when they were signed in when they sent
     * it. Null for a guest, whose booking is only reachable by its email.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ParcelPayment::class, 'booking_id');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /** Kilograms, for the quoting service. */
    public function weightKg(): float
    {
        return $this->weight_grams / 1000;
    }
}
