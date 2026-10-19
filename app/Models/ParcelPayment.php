<?php

namespace App\Models;

use App\Enums\ParcelPaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One attempt to pay for a booking.
 *
 * `reference` is generated here, sent to the PSP, and used as the idempotency
 * key when the confirmation comes back. It is the only thing that makes a
 * retried webhook harmless.
 */
class ParcelPayment extends Model
{
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'booking_id', 'provider', 'reference', 'amount_cents', 'currency',
        'status', 'provider_charge_id', 'raw', 'paid_at',
    ];

    protected $casts = [
        'status' => ParcelPaymentStatus::class,
        'amount_cents' => 'integer',
        'raw' => 'array',
        'paid_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            if (blank($payment->getKey())) {
                $payment->setAttribute($payment->getKeyName(), 'pay_'.Str::lower(Str::ulid()));
            }

            if (blank($payment->reference)) {
                $payment->setAttribute('reference', Str::lower(Str::random(16)));
            }
        });
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(ParcelBooking::class, 'booking_id');
    }

    public function succeeded(): bool
    {
        return $this->status === ParcelPaymentStatus::Succeeded;
    }
}
