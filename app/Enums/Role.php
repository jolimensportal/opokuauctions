<?php

namespace App\Enums;

/**
 * The three roles in the platform.
 *
 * The only thing that distinguishes Ops from Owner is money: an Owner may
 * release funds held in a disputed state. Everything else Ops can do, an Owner
 * can do too.
 */
enum Role: string
{
    /** A paying customer. Never gets admin access. */
    case Buyer = 'buyer';

    /** Runs the corridor day to day: bookings, milestones, allocations, messaging. */
    case Ops = 'ops';

    /** Ops plus the authority to release disputed funds and adjust balances. */
    case Owner = 'owner';

    /** Anyone who belongs in the admin panel. */
    public function canAccessPanel(): bool
    {
        return $this !== self::Buyer;
    }

    /** Only an Owner can move money out of a disputed state. */
    public function canReleaseDisputedFunds(): bool
    {
        return $this === self::Owner;
    }

    /** Ops may book shipments, record milestones, allocate lots, message buyers. */
    public function canOperate(): bool
    {
        return $this === self::Ops || $this === self::Owner;
    }

    public function label(): string
    {
        return match ($this) {
            self::Buyer => 'Buyer',
            self::Ops => 'Operations',
            self::Owner => 'Owner',
        };
    }
}