<?php

namespace App\Enums;

/**
 * The state of one attempt to pay for a booking.
 *
 * A booking can have several. The first link expiring and the customer tapping
 * a second one is normal, not an error, and the attempts are kept so that a
 * booking's history shows what was actually tried.
 */
enum ParcelPaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
