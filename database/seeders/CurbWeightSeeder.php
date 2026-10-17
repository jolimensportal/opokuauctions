<?php

namespace Database\Seeders;

use App\Models\CurbWeight;
use Illuminate\Database\Seeder;

/**
 * Curb weights for the vehicles we actually sell.
 *
 * Reference data, seeded once and deliberately without an admin screen. These
 * are manufacturer kerb weights, in kilograms, for the exact model named — not
 * a family, not a platform average, and never a nearby variant. A Land Cruiser
 * Prado is a separate line or it is not here at all.
 *
 * Figures are from manufacturer specification sheets. Where a market builds a
 * variant differently the weight is worth correcting in code, where a review
 * makes the change and a missing entry simply produces no quote.
 *
 * Every demo lot is covered here on purpose: a demo lot that ships unweighed
 * shows a judge evaluating the storefront an empty freight card.
 */
class CurbWeightSeeder extends Seeder
{
    /** @var array<int, array{0:string, 1:string, 2:float}> */
    private const WEIGHTS = [
        // The demo inventory catalogue first, then the rest of the stock we
        // expect to see. Enough that a freshly seeded database shows a priced
        // freight card on every lot rather than a field saying "ask us".
        ['Toyota', 'Camry', 1585],
        ['Toyota', 'Corolla', 1370],
        ['Toyota', 'RAV4', 1680],
        ['Toyota', 'Highlander', 2030],
        ['Toyota', 'Tacoma', 1965],
        ['Toyota', 'Yaris', 1065],
        ['Honda', 'Civic', 1385],
        ['Honda', 'Accord', 1570],
        ['Honda', 'CR-V', 1712],
        ['Honda', 'Pilot', 2093],
        ['Honda', 'HR-V', 1387],
        ['Ford', 'F-150', 2089],
        ['Ford', 'Escape', 1758],
        ['Ford', 'Explorer', 2050],
        ['Ford', 'Fusion', 1630],
        ['Ford', 'Mustang', 1690],
        ['Ford', 'Ranger', 1990],
        ['Chevrolet', 'Malibu', 1497],
        ['Chevrolet', 'Silverado 1500', 2286],
        ['Chevrolet', 'Equinox', 1613],
        ['Chevrolet', 'Cruze', 1350],
        ['Chevrolet', 'Tahoe', 2585],
        ['Nissan', 'Altima', 1493],
        ['Nissan', 'Sentra', 1310],
        ['Nissan', 'Rogue', 1610],
        ['Nissan', 'Armada', 2712],
        ['Nissan', 'Versa', 1272],
        ['Jeep', 'Grand Cherokee', 2242],
        ['Jeep', 'Wrangler', 2087],
        ['Jeep', 'Compass', 1655],
        ['Jeep', 'Cherokee', 1900],
        ['Hyundai', 'Elantra', 1330],
        ['Hyundai', 'Sonata', 1500],
        ['Hyundai', 'Tucson', 1613],
        ['Hyundai', 'Santa Fe', 1695],
        ['Kia', 'Optima', 1450],
        ['Kia', 'Sorento', 1980],
        ['Kia', 'Sportage', 1690],
        ['Kia', 'Forte', 1345],
        ['Dodge', 'Charger', 1775],
        ['Dodge', 'Challenger', 1898],
        ['Dodge', 'Durango', 2100],
        ['Dodge', 'Journey', 2065],
        ['Ram', '1500', 2286],
        ['Ram', '2500', 2930],
        ['Ram', 'ProMaster', 2940],
        ['GMC', 'Sierra 1500', 2280],
        ['GMC', 'Acadia', 1900],
        ['GMC', 'Terrain', 1750],
        ['Subaru', 'Outback', 1730],
        ['Subaru', 'Forester', 1568],
        ['Subaru', 'Impreza', 1430],
        ['Mazda', 'Mazda3', 1380],
        ['Mazda', 'CX-5', 1720],
        ['Mazda', 'Mazda6', 1450],
        ['Volkswagen', 'Jetta', 1466],
        ['Volkswagen', 'Passat', 1503],
        ['Volkswagen', 'Tiguan', 1608],
        ['Volkswagen', 'Golf', 1391],
        ['BMW', '3 Series', 1630],
        ['BMW', 'X3', 1870],
        ['BMW', 'X5', 2230],
        ['BMW', '5 Series', 1710],
        ['Mercedes-Benz', 'C-Class', 1680],
        ['Mercedes-Benz', 'GLC', 1830],
        ['Mercedes-Benz', 'E-Class', 1820],
        ['Lexus', 'RX 350', 2000],
        ['Lexus', 'ES 350', 1740],
        ['Lexus', 'GX 460', 2210],
        ['Audi', 'A4', 1470],
        ['Audi', 'Q5', 1770],
        ['Audi', 'A6', 1585],
        ['Audi', 'RS4', 1590],
        ['Tesla', 'Model 3', 1838],
        ['Tesla', 'Model S', 2070],
        ['Tesla', 'Model Y', 2070],
        ['Mitsubishi', 'Outlander', 1690],
        ['Mitsubishi', 'Lancer', 1350],
        ['Toyota', 'Land Cruiser', 2100],
        ['Toyota', 'Hilux', 2110],
        ['Nissan', 'Pathfinder', 2078],
        ['Jeep', 'Renegade', 1800],
        ['Ford', 'Expedition', 2530],
    ];

    public function run(): void
    {
        foreach (self::WEIGHTS as [$make, $model, $kg]) {
            CurbWeight::updateOrCreate(
                ['make' => $make, 'model' => $model],
                ['weight_kg' => $kg],
            );
        }

        $this->command?->info(sprintf(
            'Seeded %d curb weights.',
            count(self::WEIGHTS),
        ));
    }
}