<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The corridors we originate parcels from, and the price of getting one to Tema.
 *
 * Two different kinds of fact live in this file and they must not be confused:
 *
 *   GEOGRAPHY IS SETTLED. Newark is in New Jersey, 07030 is a New Jersey
 *   postcode, Houston is in Texas. Zones and postcode bands are groupings we
 *   chose, not prices we guessed, so they are safe to seed.
 *
 *   PRICE IS NOT. The only corridor cost we hold with confidence is the
 *   forwarder's invoice for na-tema: USD 0.32 per kg, recorded by
 *   FreightRateSeeder from what we are actually billed. That is a COST. It is
 *   not a price a customer should be quoted, because quoting our cost is how
 *   we go bankrupt quietly rather than loudly.
 *
 * So the Newark parcel row below uses the corridor cost as its per-kg and a
 * placeholder minimum, and it is labelled as a demo tariff in the row name.
 * Replace PARCEL_MINIMUM_CENTS and set a retail per-kg on top of the corridor
 * cost before this card quotes a paying customer. Everything else is left
 * unseeded on purpose: an origin with a band but no rate is what lets the
 * quote page say "we do not ship from here yet" honestly, instead of quoting
 * a number nobody approved.
 *
 * In production this seeder does not run. Rates are entered and versioned by
 * hand, which is the entire reason rate_card_rows is an append-only table.
 */
class LogisticsRateSeeder extends Seeder
{
    /** DEMO ONLY — not an approved retail price. See class docblock. */
    private const PARCEL_MINIMUM_CENTS = 2500;

    /** Air divisor, L-5000. Not negotiable for a given lane. */
    private const AIR_DIVISOR = 5000;

    public function run(): void
    {
        // Origin zones. The corridors operations has said we collect from, even
        // where we have not yet agreed a price.
        foreach ([
            ['nj-newark', 'us', 'NJ', 'Newark, New Jersey', true],
            ['tx-houston', 'us', 'TX', 'Houston, Texas', true],
            ['ga-savannah', 'us', 'GA', 'Savannah, Georgia', true],
            ['ca-losangeles', 'us', 'CA', 'Los Angeles, California', true],
            ['ca-toronto', 'ca', 'ON', 'Toronto, Ontario', true],
            ['ca-vancouver', 'ca', 'BC', 'Vancouver, British Columbia', true],
        ] as [$id, $country, $code, $name, $active]) {
            DB::table('origin_zones')->updateOrInsert(
                ['id' => $id],
                ['country' => $country, 'code' => $code, 'name' => $name, 'active' => $active],
            );
        }

        // Postcode bands. Geographic, so settled. Note these are prefixes and
        // therefore deliberately coarse — a band's job is to route a postcode to
        // the zone that will price it, not to describe a service area.
        foreach ([
            ['nj-newark', 'us', '07'],
            ['nj-newark', 'us', '08'],
            ['nj-newark', 'us', '070'],
            ['tx-houston', 'us', '77'],
            ['ga-savannah', 'us', '31'],
            ['ca-losangeles', 'us', '90'],
            ['ca-losangeles', 'us', '91'],
            ['ca-losangeles', 'us', '905'],
            ['ca-toronto', 'ca', 'M5'],
            ['ca-vancouver', 'ca', 'V6'],
        ] as [$zone, $country, $prefix]) {
            DB::table('postcode_bands')->updateOrInsert(
                ['id' => 'band_'.$prefix],
                ['country' => $country, 'prefix' => $prefix, 'zone_id' => $zone, 'active' => true],
            );
        }

        // The one card that quotes today. Named so that a screenshot of the demo
        // can never be mistaken for our published tariff.
        DB::table('rate_cards')->updateOrInsert(
            ['id' => 'card-newark-parcel-2026'],
            ['name' => 'Newark air parcel (DEMO TARIFF)', 'currency' => 'USD'],
        );

        $rows = [
            [
                'id' => 'row-newark-air-standard',
                'rate_card_id' => 'card-newark-parcel-2026',
                'origin_zone_id' => 'nj-newark',
                'destination' => 'Tema',
                'mode' => 'air',
                'unit' => 'parcel',
                'intake_method' => 'dropoff',
                'service_level' => 'standard',
                'divisor' => self::AIR_DIVISOR,
                'per_kg_cents' => 32, // forwarder corridor cost, USD 0.32/kg
                'per_pallet_cents' => null,
                'min_charge_cents' => self::PARCEL_MINIMUM_CENTS,
                'max_billable_kg' => 30,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ],
            [
                'id' => 'row-newark-pickup',
                'rate_card_id' => 'card-newark-parcel-2026',
                'origin_zone_id' => 'nj-newark',
                'destination' => 'Tema',
                'mode' => 'air',
                'unit' => 'parcel',
                'intake_method' => 'partner_pickup',
                'service_level' => 'standard',
                'divisor' => self::AIR_DIVISOR,
                // A pickup is charged as its own line item, never folded into
                // the freight rate, so a customer can see what it cost them.
                'per_kg_cents' => null,
                'per_pallet_cents' => null,
                'min_charge_cents' => 15000, // matches FreightRateSeeder pickup
                'max_billable_kg' => null,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ],
        ];

        foreach ($rows as $row) {
            DB::table('rate_card_rows')->updateOrInsert(['id' => $row['id']], $row);
        }
    }
}