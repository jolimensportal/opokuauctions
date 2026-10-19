<?php

namespace Database\Seeders;

use App\Models\GoodsDetail;
use App\Models\Lot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Goods lots, so the second half of the business is visible in a demo.
 *
 * DemoLotSeeder files 186 vehicles and no goods at all, which made the pallets
 * and bundles half of the company invisible on its own storefront — /lots?kind=goods
 * rendered an empty facet and read as "we do not sell this" rather than "we
 * have not loaded it".
 *
 * These are DEMO rows. Real inventory arrives through the admin lot form, which
 * is kind-aware and writes the same two tables. Nothing here is fabricated
 * pricing: a starting bid of 40 cents on a bed frame is the same shape of
 * placeholder as the freight demo tariff, and it is labelled as a demo seed in
 * the file rather than dressed up as a sale.
 *
 * Idempotent on a stable `lot_goods_demo_*` id, so re-seeding updates in place
 * instead of duplicating — the same reasoning as DemoLotSeeder's keying on id
 * rather than on a mutable attribute.
 */
class DemoGoodsSeeder extends Seeder
{
    /**
     * Each row is one shelf of the business: a full pallet, a part-pallet, a
     * single heavy item, a bundle of household things, and an overstock return.
     */
    private const GOODS = [
        [
            'id' => 'lot_goods_demo_01',
            'title' => 'Full pallet — mixed household',
            'provenance' => 'overstock',
            'condition' => 'mixed',
            'unit_label' => 'Pallet',
            'grade' => 'Mixed',
            'manifest_count' => 1,
            'weight_kg' => 610.0,
            'length_cm' => 120, 'width_cm' => 100, 'height_cm' => 150,
            'city' => 'Newark',
            'starting_bid' => 180_00,
        ],
        [
            'id' => 'lot_goods_demo_02',
            'title' => 'Half pallet — boxed kitchenware',
            'provenance' => 'liquidation',
            'condition' => 'grade_a',
            'unit_label' => 'Half pallet',
            'grade' => 'Grade A',
            'manifest_count' => 1,
            'weight_kg' => 285.0,
            'length_cm' => 120, 'width_cm' => 100, 'height_cm' => 90,
            'city' => 'Houston',
            'starting_bid' => 95_00,
        ],
        [
            'id' => 'lot_goods_demo_03',
            'title' => 'Bed frame — disassembled, steel',
            'provenance' => 'garage_sale',
            'condition' => 'grade_b',
            'unit_label' => 'Bed frame',
            'grade' => 'Grade B',
            'manifest_count' => 1,
            'weight_kg' => 52.0,
            'length_cm' => 210, 'width_cm' => 160, 'height_cm' => 40,
            'city' => 'Detroit',
            'starting_bid' => 40_00,
        ],
        [
            'id' => 'lot_goods_demo_04',
            'title' => 'Garage bundle — tools and garden, 14 pieces',
            'provenance' => 'garage_sale',
            'condition' => 'mixed',
            'unit_label' => 'Bundle',
            'grade' => 'Mixed',
            'manifest_count' => 14,
            'weight_kg' => 96.5,
            'length_cm' => 120, 'width_cm' => 80, 'height_cm' => 60,
            'city' => 'Cleveland',
            'starting_bid' => 65_00,
        ],
        [
            'id' => 'lot_goods_demo_05',
            'title' => 'Returns pallet — small appliances',
            'provenance' => 'trade_in',
            'condition' => 'returns',
            'unit_label' => 'Pallet',
            'grade' => 'Returns',
            'manifest_count' => 1,
            'weight_kg' => 430.0,
            'length_cm' => 120, 'width_cm' => 100, 'height_cm' => 140,
            'city' => 'Newark',
            'starting_bid' => 120_00,
        ],
        [
            'id' => 'lot_goods_demo_06',
            'title' => 'Headlamp — single unit',
            'provenance' => 'garage_sale',
            'condition' => 'grade_b',
            'unit_label' => 'Headlamp',
            'grade' => 'Grade B',
            'manifest_count' => 1,
            'weight_kg' => 2.4,
            'length_cm' => 30, 'width_cm' => 25, 'height_cm' => 20,
            'city' => 'Newark',
            'starting_bid' => 13_00,
        ],
    ];

    public function run(): void
    {
        $ids = array_column(self::GOODS, 'id');

        // Clear anything downstream before the lots themselves, or the foreign
        // keys on bids and shipments block the delete.
        DB::table('shipment_lots')->whereIn('lot_id', $ids)->delete();
        DB::table('bid')->whereIn('lot_id', $ids)->delete();
        DB::table('goods_detail')->whereIn('lot_id', $ids)->delete();

        $now = now();

        foreach (self::GOODS as $i => $goods) {
            Lot::updateOrCreate(
                ['id' => $goods['id']],
                [
                    'kind' => 'goods',
                    'provenance' => $goods['provenance'],
                    'title' => $goods['title'],
                    // What a buyer actually wants to know, and the one thing a
                    // pallet does not have: a VIN or a year.
                    'description' => $goods['manifest_count'] > 1
                        ? sprintf('%d pieces, %s. Photographed at intake.', $goods['manifest_count'], $goods['grade'])
                        : sprintf('One %s, %s. Photographed at intake.', strtolower($goods['unit_label']), $goods['grade']),
                    'city' => $goods['city'],
                    'photos' => [],
                    'condition' => $goods['condition'],
                    // Weighed, not estimated. A goods lot has no make and model
                    // to look a weight up against, which is exactly why freight
                    // cannot be quoted for one that has not been weighed.
                    'weight_kg' => $goods['weight_kg'],
                    'currency_code' => 'usd',
                    'starting_bid' => $goods['starting_bid'],
                    'reserve_price' => 0,
                    'bid_increment' => 500,
                    'status' => 'LIVE',
                    'current_bid' => 0,
                    'bid_count' => 0,
                    'highest_bidder_id' => null,
                    'extensions' => 0,
                    'starts_at' => $now->copy()->subDay(),
                    'ends_at' => $now->copy()->addDays(2 + ($i % 3)),
                ],
            );

            GoodsDetail::updateOrCreate(
                ['lot_id' => $goods['id']],
                [
                    'unit_label' => $goods['unit_label'],
                    'grade' => $goods['grade'],
                    'manifest_count' => $goods['manifest_count'],
                    'length_cm' => $goods['length_cm'],
                    'width_cm' => $goods['width_cm'],
                    'height_cm' => $goods['height_cm'],
                ],
            );
        }

        $this->command?->info(sprintf('Seeded %d demo goods lots.', count(self::GOODS)));
    }
}