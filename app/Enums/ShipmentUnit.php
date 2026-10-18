<?php

namespace App\Enums;

/**
 * What shape of thing is being sent.
 *
 * Priced differently because it is handled differently: a parcel is priced per
 * parcel by every carrier, a pallet by its footprint.
 */
enum ShipmentUnit: string
{
    case Parcel = 'parcel';

    case Pallet = 'pallet';

    public function isPallet(): bool
    {
        return $this === self::Pallet;
    }
}
