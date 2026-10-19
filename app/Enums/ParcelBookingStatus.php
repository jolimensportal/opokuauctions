<?php

namespace App\Enums;

/**
 * Where a booking has got to.
 *
 * EXPIRED and ABANDONED are the two that get conflated, and keeping them apart
 * is the whole reason they are separate cases rather than one DEAD state:
 * EXPIRED means we issued a link and nobody took it, ABANDONED means nobody ever
 * answered. Merging them produces a single abandonment number describing two
 * unrelated behaviours, and any change to the checkout would then be unable to
 * tell which one it had fixed.
 */
enum ParcelBookingStatus: string
{
    case Quoted = 'quoted';
    case PaymentPending = 'payment_pending';
    case Booked = 'booked';
    case Expired = 'expired';
    case Abandoned = 'abandoned';

    /** Terminal: no further transition is legal. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Booked, self::Expired, self::Abandoned], true);
    }

    /** Still waiting on someone, so a sweep has not finished with it yet. */
    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }
}
