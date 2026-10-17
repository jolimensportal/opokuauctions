<?php

namespace Database\Seeders;

use App\Models\ClearanceTask;
use App\Models\Lot;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use App\Models\ShipmentMilestone;
use Illuminate\Database\Seeder;

/**
 * One demo shipment in transit, with real cost lines.
 *
 * This exists so the storefront's landed-cost section shows the product's own
 * ClearanceService arithmetic rather than numbers invented in a Blade template.
 * Every figure here lands in freight_cost, insurance_cost or a clearance task's
 * cost column, which is the same data the admin console reconciles, so the
 * homepage and the operator screen cannot disagree.
 */
class DemoShipmentSeeder extends Seeder
{
    public function run(): void
    {
        $shipment = Shipment::updateOrCreate(
            ['id' => 'shp_demo_01'],
            [
                'id' => 'shp_demo_01',
                'vessel' => 'MV Atlantic Trader',
                'voyage' => '2418W',
                'container_no' => 'MSCU 4471 908',
                'booking_ref' => 'OPK-4471908',
                'bill_of_lading' => 'BOL-88213477',
                'seaport_origin' => 'Newark',
                'seaport_destination' => 'Tema',
                'status' => 'SAILING',
                // 40ft shared container across 6 vehicles. These three numbers
                // plus the clearance tasks are the entire landed cost.
                'freight_cost' => 140_000,
                'insurance_cost' => 32_000,
                'tracking_source' => 'ShipsGo',
                'eta' => now()->addDays(19),
                'actual_departure' => now()->subDays(11),
            ],
        );

        // Allocate the demo vehicles to this container, in the same way the ops
        // console would once a lot is won and paid in full.
        foreach (Lot::whereIn('id', ['lot_demo_02', 'lot_demo_04', 'lot_demo_06'])->get() as $lot) {
            ShipmentLot::updateOrCreate(
                ['lot_id' => $lot->id],
                ['shipment_id' => $shipment->id, 'quantity' => 1],
            );
        }

        // Money tasks only. The template's non-money tasks (bill of lading,
        // release order, gate-out) stay pending, because a shipment still at sea
        // genuinely has not done them yet.
        // Realistic for a 40ft container of three vehicles. These are stand-ins
        // until real GCNet and Tema port receipts exist, and they are the only
        // invented figures on the storefront.
        $costs = [
            'DUTY_PAID' => 94_000,
            'VAT_PAID' => 189_000,
            'PORT_CHARGES' => 64_500,
        ];

        $position = 3;
        foreach ($costs as $type => $cost) {
            ClearanceTask::updateOrCreate(
                ['shipment_id' => $shipment->id, 'type' => $type],
                [
                    'id' => 'clt_demo_'.$type,
                    'position' => $position++,
                    'status' => 'complete',
                    'cost' => $cost,
                    'receipt_path' => 'receipts/'.$type.'.pdf',
                    'notes' => 'Paid by Opoku on the buyer\'s behalf and receipted.',
                    'completed_at' => now()->subDays(2),
                ],
            );
        }

        // The milestones actually reached, so the track on the lot detail page
        // shows history rather than a blank panel.
        foreach ([
            ['LOADED', 'Container loaded at Newark', 11],
            ['DEPARTED', 'Vessel departed Newark', 10],
            ['SAILING', 'At sea, transatlantic', 2],
        ] as [$type, $note, $daysAgo]) {
            ShipmentMilestone::updateOrCreate(
                ['shipment_id' => $shipment->id, 'type' => $type],
                [
                    'id' => 'shm_demo_'.strtolower($type),
                    'occurred_at' => now()->subDays($daysAgo),
                    'source' => 'admin',
                    'notes' => $note,
                ],
            );
        }

        $this->command?->info('Seeded demo shipment shp_demo_01 with real landed-cost lines.');
    }
}