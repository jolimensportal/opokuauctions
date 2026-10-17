<?php

namespace Database\Seeders;

use App\Models\Bid;
use App\Models\Lot;
use App\Models\VehicleDetail;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Demo inventory for local development and for judging the storefront.
 *
 * These are real rows in the real schema, not a fixture format, so anything
 * that renders in development renders from the same shape production will.
 *
 * Photos are real Unsplash images of actual vehicles, chosen because they exist
 * and show cars. They are placeholders for imagery the business will supply;
 * they are not intended to ship.
 */
class DemoLotSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // Earlier development sessions filled the tables with synthetic factory
        // rows whose VINs carry a "VIN-" prefix. Left in place they make the
        // storefront report a hundred lots while showing six, which reads as a
        // broken page rather than as seed data. Only that exact prefix is
        // removed, so this seeder can never delete inventory imported for real.
        //
        // The VIN lives in vehicle_detail now, so the search for synthetic rows
        // goes through there and the matching lot ids are collected once.
        $syntheticLotIds = DB::table('vehicle_detail')
            ->where('vin', 'like', 'VIN-%')
            ->pluck('lot_id');

        Bid::whereIn('lot_id', $syntheticLotIds)->delete();
        DB::table('shipment_lots')->whereIn('lot_id', $syntheticLotIds)->delete();
        DB::table('vehicle_detail')->whereIn('lot_id', $syntheticLotIds)->delete();
        $stale = Lot::whereIn('id', $syntheticLotIds)->delete();

        $lots = [
            [
                'vin' => 'WAUCNAF51JA123456',
                'year' => 2018,
                'make' => 'Audi',
                'model' => 'RS4',
                'trim' => 'Quattro',
                'city' => 'Newark',
                'condition' => 'clean_title',
                'odometer' => 184_230,
                'damage_primary' => 'None recorded',
                'currency_code' => 'usd',
                'starting_bid' => 145_000,
                'reserve_price' => 190_000,
                'bid_increment' => 5_000,
                'status' => 'LIVE',
                'current_bid' => 215_000,
                'bid_count' => 9,
                'ends_at' => $now->copy()->addHours(6),
                'photos' => ['https://images.unsplash.com/photo-1606664515524-ed2f786a0bd6?w=1200&q=80'],
            ],
            [
                'vin' => '1FTFW1E50MFA12345',
                'year' => 2021,
                'make' => 'Ford',
                'model' => 'F-150',
                'trim' => 'XLT SuperCrew',
                'city' => 'Detroit',
                'condition' => 'salvage',
                'odometer' => 72_410,
                'damage_primary' => 'Front end',
                'currency_code' => 'usd',
                'starting_bid' => 320_000,
                'reserve_price' => 410_000,
                'bid_increment' => 10_000,
                'status' => 'LIVE',
                'current_bid' => 385_000,
                'bid_count' => 14,
                'ends_at' => $now->copy()->addHours(19),
                'photos' => ['https://images.unsplash.com/photo-1551830820-330a71b99659?w=1200&q=80'],
            ],
            [
                'vin' => '5YJ3E1EA8KF317654',
                'year' => 2018,
                'make' => 'Tesla',
                'model' => 'Model 3',
                'trim' => 'Long Range',
                'city' => 'Miami',
                'condition' => 'salvage',
                'odometer' => 48_900,
                'damage_primary' => 'Rear',
                'currency_code' => 'usd',
                'starting_bid' => 260_000,
                'reserve_price' => 0,
                'bid_increment' => 5_000,
                'status' => 'LIVE',
                'current_bid' => 260_000,
                'bid_count' => 0,
                'ends_at' => $now->copy()->addDay(2),
                'photos' => ['https://images.unsplash.com/photo-1560958089-b8a1929cea89?w=1200&q=80'],
            ],
            [
                'vin' => 'JN8AZ2NE7E9254321',
                'year' => 2015,
                'make' => 'Nissan',
                'model' => 'Armada',
                'trim' => 'Platinum',
                'city' => 'Houston',
                'condition' => 'project',
                'odometer' => 132_770,
                'damage_primary' => 'Flood/water',
                'currency_code' => 'usd',
                'starting_bid' => 95_000,
                'reserve_price' => 120_000,
                'bid_increment' => 2_500,
                'status' => 'LIVE',
                'current_bid' => 107_500,
                'bid_count' => 5,
                'ends_at' => $now->copy()->addHours(3),
                'photos' => ['https://images.unsplash.com/photo-1519641471654-76ce0107ad1b?w=1200&q=80'],
            ],
            [
                'vin' => '2C3CDXBG9JH123456',
                'year' => 2018,
                'make' => 'Dodge',
                'model' => 'Challenger',
                'trim' => 'R/T',
                'city' => 'Baltimore',
                'condition' => 'clean_title',
                'odometer' => 38_600,
                'damage_primary' => 'None recorded',
                'currency_code' => 'usd',
                'starting_bid'            => 210_000,
                'reserve_price' => 240_000,
                'bid_increment' => 5_000,
                'status' => 'UPCOMING',
                'current_bid' => 210_000,
                'bid_count' => 0,
                'ends_at' => $now->copy()->addDays(4),
                'photos' => ['https://images.unsplash.com/photo-1626668893632-6f3a4466d22f?w=1200&q=80'],
            ],
            [
                'vin' => '1FMJK1KT8KEA12345',
                'year' => 2019,
                'make' => 'Ford',
                'model' => 'Expedition',
                'trim' => 'Limited',
                'city' => 'Chicago',
                'condition' => 'salvage',
                'odometer' => 96_450,
                'damage_primary' => 'Side',
                'currency_code' => 'usd',
                'starting_bid' => 140_000,
                'reserve_price' => 175_000,
                'bid_increment' => 5_000,
                'status' => 'LIVE',
                'current_bid' => 152_500,
                'bid_count' => 3,
                'ends_at' => $now->copy()->addHours(11),
                'photos' => ['https://images.unsplash.com/photo-1533473359331-0135ef1b58bf?w=1200&q=80'],
            ],
        ];

        foreach ($lots as $i => $lot) {
            // Keyed on an explicit, stable id rather than the VIN: editing a
            // seeded lot's year or make is an ordinary edit, and keying on the
            // VIN would insert a duplicate row instead of updating in place.
            $id = 'lot_demo_'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);

            // Everything except the vehicle facts, which now live in
            // vehicle_detail. A pallet has no VIN, and these are all vehicles.
            // partition() returns [matching, non-matching] and the callback
            // receives the VALUE, not the key.
            [$shared, $vehicle] = Arr::partition(
                $lot,
                fn ($value, $key) => ! in_array($key, ['vin', 'year', 'make', 'model', 'trim', 'odometer', 'damage_primary'], true),
            );

            Lot::updateOrCreate(
                ['id' => $id],
                $shared + [
                    'kind' => 'vehicle',
                    'provenance' => 'salvage_auction',
                    'title' => $vehicle['year'].' '.$vehicle['make'].' '.$vehicle['model'],
                    'starts_at' => $now->copy()->subDay(),
                    'extensions' => 0,
                    'highest_bidder_id' => null,
                ],
            );

            VehicleDetail::updateOrCreate(
                ['lot_id' => $id],
                $vehicle,
            );
        }

        $this->command?->info(sprintf(
            'Seeded %d demo lots (%d synthetic rows cleared).',
            count($lots),
            $stale,
        ));
    }
}