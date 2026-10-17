<?php

namespace Database\Seeders;

use App\Models\Lot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Filler inventory so the browse experience has the shape of a real catalogue.
 *
 * This exists for one reason: the storefront's whole design rests on facet
 * counts, and with six lots the page reads "5 lots open, Ford (2)". Against a
 * reference site advertising 288,689 cars that looks like an empty shop, and it
 * makes the layout impossible to judge — you cannot see whether a price band
 * grid holds up at forty items when you have three.
 *
 * These rows are honest filler: real columns, real prices, real counts. Only
 * the photographs are pooled rather than per-vehicle, which is called out in the
 * DemoLotSeeder comment and is the single thing here that is not per-lot truth.
 */
class DemoInventorySeeder extends Seeder
{
    /** Makes weighted roughly the way a North American salvage yard actually is. */
    private const MAKES = [
        'Toyota' => 22, 'Honda' => 16, 'Ford' => 18, 'Chevrolet' => 12,
        'Nissan' => 11, 'Jeep' => 8, 'Hyundai' => 7, 'Kia' => 5,
        'Dodge' => 5, 'Ram' => 4, 'GMC' => 4, 'Subaru' => 4,
        'Mazda' => 3, 'Volkswagen' => 3, 'BMW' => 3, 'Mercedes-Benz' => 2,
        'Lexus' => 2, 'Audi' => 2, 'Tesla' => 2, 'Mitsubishi' => 2,
    ];

    private const MODELS = [
        'Toyota' => ['Camry', 'Corolla', 'RAV4', 'Highlander', 'Tacoma', 'Yaris'],
        'Honda' => ['Civic', 'Accord', 'CR-V', 'Pilot', 'HR-V'],
        'Ford' => ['F-150', 'Escape', 'Explorer', 'Fusion', 'Mustang', 'Ranger'],
        'Chevrolet' => ['Malibu', 'Silverado 1500', 'Equinox', 'Cruze', 'Tahoe'],
        'Nissan' => ['Altima', 'Sentra', 'Rogue', 'Armada', 'Versa'],
        'Jeep' => ['Grand Cherokee', 'Wrangler', 'Compass', 'Cherokee'],
        'Hyundai' => ['Elantra', 'Sonata', 'Tucson', 'Santa Fe'],
        'Kia' => ['Optima', 'Sorento', 'Sportage', 'Forte'],
        'Dodge' => ['Charger', 'Challenger', 'Durango', 'Journey'],
        'Ram' => ['1500', '2500', 'ProMaster'],
        'GMC' => ['Sierra 1500', 'Acadia', 'Terrain'],
        'Subaru' => ['Outback', 'Forester', 'Impreza'],
        'Mazda' => ['Mazda3', 'CX-5', 'Mazda6'],
        'Volkswagen' => ['Jetta', 'Passat', 'Tiguan', 'Golf'],
        'BMW' => ['3 Series', 'X3', 'X5', '5 Series'],
        'Mercedes-Benz' => ['C-Class', 'GLC', 'E-Class'],
        'Lexus' => ['RX 350', 'ES 350', 'GX 460'],
        'Audi' => ['A4', 'Q5', 'A6'],
        'Tesla' => ['Model 3', 'Model S', 'Model Y'],
        'Mitsubishi' => ['Outlander', 'Lancer'],
    ];

    private const CONDITIONS = [
        'clean_title' => 26, 'salvage' => 48, 'project' => 18, 'parts_only' => 8,
    ];

    private const CITIES = [
        'Newark' => 14, 'Detroit' => 16, 'Houston' => 11, 'Chicago' => 10,
        'Miami' => 8, 'Baltimore' => 7, 'Dallas' => 9, 'Philadelphia' => 7,
        'Los Angeles' => 8, 'Savannah' => 6, 'Long Beach' => 5, 'Bronx' => 5,
    ];

    private const DAMAGE = [
        'Front end', 'Rear', 'Side', 'Flood/water', 'Hail', 'Undercarriage',
        'Rollover', 'Burn - engine', 'Mechanical', 'None recorded',
    ];

    /** Pool of real photographs. Not per-lot truth; see the class comment. */
    private const PHOTOS = [
        'https://images.unsplash.com/photo-1533473359331-0135ef1b58bf?w=1200&q=80',
        'https://images.unsplash.com/photo-1606664515524-ed2f786a0bd6?w=1200&q=80',
        'https://images.unsplash.com/photo-1551830820-330a71b99659?w=1200&q=80',
        'https://images.unsplash.com/photo-1560958089-b8a1929cea89?w=1200&q=80',
        'https://images.unsplash.com/photo-1519641471654-76ce0107ad1b?w=1200&q=80',
        'https://images.unsplash.com/photo-1626668893632-6f3a4466d22f?w=1200&q=80',
        'https://images.unsplash.com/photo-1502877338535-766e1452684a?w=1200&q=80',
        'https://images.unsplash.com/photo-1494976388531-d1058494cdd8?w=1200&q=80',
    ];

    public function run(): void
    {
        // Read from the environment rather than $this->command->option(), which
        // only exists when run as a dedicated Artisan command, not under
        // `db:seed --class=`. Default is enough for a realistic catalogue.
        $count = (int) (env('DEMO_LOTS', 180));

        // Wipe only the generated rows so the six hand-made lots in
        // DemoLotSeeder, which have per-vehicle photography, survive.
        Lot::where('id', 'like', 'lot_fx%')->delete();

        $now = Carbon::now();
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $make = $this->weighted(self::MAKES);
            $condition = $this->weighted(self::CONDITIONS);
            $city = $this->weighted(self::CITIES);
            $model = collect(self::MODELS[$make])->random();
            $year = random_int(2004, 2024);

            // Price correlates with age and inversely with damage, the way a real
            // yard prices. Random prices independent of the car make the price
            // bands meaningless, which is the thing the homepage is about.
            $base = random_int(40_000, 900_000);
            $ageFactor = max(0.25, 1 - (2024 - $year) * 0.055);
            $conditionFactor = match ($condition) {
                'clean_title' => 1.15,
                'salvage' => 0.8,
                'project' => 0.6,
                'parts_only' => 0.3,
            };
            $starting = (int) round($base * $ageFactor * $conditionFactor / 500) * 500;

            $increment = max(2_500, (int) round($starting / 25 / 500) * 500);
            $bidCount = random_int(0, 22);
            $current = $starting + ($bidCount * $increment);

            // Roughly a fifth are upcoming, the rest live and spread over the next
            // ten days so every closing band on the homepage has something in it.
            $status = random_int(1, 6) === 1 ? 'UPCOMING' : 'LIVE';
            $endsAt = $status === 'UPCOMING'
                ? $now->copy()->addDays(random_int(4, 14))
                : $now->copy()->addMinutes(random_int(12, 60 * 24 * 9));

            $vin = $this->vin($year, $make);

            $rows[] = [
                'id' => 'lot_fx'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'vin' => $vin,
                'year' => $year,
                'make' => $make,
                'model' => $model,
                'trim' => Str::random(1).strtoupper(Str::random(5)),
                'city' => $city,
                'condition' => $condition,
                'odometer' => random_int(4_000, 245_000),
                'damage_primary' => collect(self::DAMAGE)->random(),
                'currency_code' => 'usd',
                'starting_bid' => $starting,
                'reserve_price' => random_int(1, 5) === 1 ? 0 : (int) round($current * 1.08 / 500) * 500,
                'bid_increment' => $increment,
                'status' => $status,
                'current_bid' => $current,
                'bid_count' => $bidCount,
                'extensions' => 0,
                'highest_bidder_id' => null,
                'starts_at' => $now->copy()->subDays(random_int(1, 14)),
                'ends_at' => $endsAt,
                // insert() goes straight to the query builder and bypasses the
                // model's array cast, so the JSON has to be encoded here or
                // Postgres receives a PHP array and throws.
                'photos' => json_encode([self::PHOTOS[array_rand(self::PHOTOS)]]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Chunked so a large catalogue does not build one enormous statement.
        foreach (array_chunk($rows, 50) as $chunk) {
            Lot::insert($chunk);
        }

        $this->command?->info(sprintf('Seeded %d generated demo lots.', $count));
    }

    /**
     * Pick a key from a weight map.
     *
     * @param  array<string, int>  $weights
     */
    private function weighted(array $weights): string
    {
        $total = array_sum($weights);
        $roll = random_int(1, max(1, $total));

        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return (string) array_key_first($weights);
    }

    /**
     * A syntactically plausible VIN: WMI + VDS + check digit + year + plant.
     *
     * Not a real VIN and not registered to anyone. It exists so the 17
     * characters line up and the mono column renders at a realistic width,
     * which a placeholder like "VIN-00001" does not.
     */
    private function vin(int $year, string $make): string
    {
        $wmi = match ($make) {
            'Toyota' => '4T1', 'Honda' => '1HG', 'Ford' => '1FT', 'Chevrolet' => '1GC',
            'Nissan' => '1N4', 'Jeep' => '1C4', 'Hyundai' => '5NP', 'Kia' => '5XY',
            'Dodge' => '2C3', 'Ram' => '1C6', 'GMC' => '1GT', 'Subaru' => '4S3',
            'Mazda' => 'JM1', 'Volkswagen' => '3VW', 'BMW' => 'WBA', 'Mercedes-Benz' => 'WDD',
            'Lexus' => 'JTH', 'Audi' => 'WAU', 'Tesla' => '5YJ', 'Mitsubishi' => 'JA4',
            default => '1FT',
        };

        // Position 10 encodes the model year on the standard cycle.
        $yearCode = 'ABCDEFGHJKLMNPRSTVWXY123456789'[(($year - 1980) % 30 + 30) % 30];

        return $wmi
            .strtoupper(Str::random(5))
            .(string) random_int(0, 9)
            .$yearCode
            .strtoupper(Str::random(1))
            .random_int(100000, 999999);
    }
}