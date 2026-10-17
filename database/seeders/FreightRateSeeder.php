<?php

namespace Database\Seeders;

use App\Models\FreightRate;
use Illuminate\Database\Seeder;

/**
 * What the corridor costs to run.
 *
 * The figures are the forwarder's current invoice, not estimates: they include
 * port handling at Tema, which is why the minimum is USD 250.00 on a lane where
 * a light car would work out below it.
 *
 * Written with updateOrCreate so re-seeding corrects a rate that operations have
 * been editing in the admin, rather than failing on the unique lane key or
 * quietly leaving last quarter's price in place. In production this seeder does
 * not run; the row is edited by hand, which is the whole reason it is a table.
 */
class FreightRateSeeder extends Seeder
{
    public function run(): void
    {
        FreightRate::updateOrCreate(
            ['lane' => 'na-tema'],
            [
                'usd_per_kg' => 0.32,
                // The forwarder's minimum consignment charge. A 500kg hatchback
                // works out at USD 160 and is still carried for USD 250.
                'min_charge_cents' => 25000,
                // Flat per car, not per kilo: cover is priced on the vehicle.
                'insurance_cents' => 10700,
                // Only charged when the buyer asks us to collect from the port.
                'pickup_cents' => 15000,
                'active' => true,
            ],
        );
    }
}