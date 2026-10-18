<?php

namespace App\Enums;

/**
 * How the goods reach us.
 *
 * All three exist from day one so that adding the third later is a rate change
 * rather than a schema change or a code branch.
 */
enum IntakeMethod: string
{
    /** The sender hands it to a warehouse themselves. Cheapest to run. */
    case Dropoff = 'dropoff';

    /** A partner courier collects it and bills per stop. */
    case PartnerPickup = 'partner_pickup';

    /** We collect it. Modelled now, launched later. */
    case SelfPickup = 'self_pickup';

    public function label(): string
    {
        return match ($this) {
            self::Dropoff => 'Warehouse drop-off',
            self::PartnerPickup => 'Courier pickup',
            self::SelfPickup => 'Collected by us',
        };
    }
}
