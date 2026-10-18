<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Someone who asked for a price and wanted to book it.
 *
 * Not a customer. No password and no address, because at this point we have an
 * email and a question, and everything beyond that would be data we have no
 * consent to hold.
 */
class RateReservation extends Model
{
    protected $fillable = [
        'email', 'country', 'postcode', 'unit', 'quoted_total_cents',
    ];

    protected function casts(): array
    {
        return [
            'quoted_total_cents' => 'integer',
        ];
    }
}
