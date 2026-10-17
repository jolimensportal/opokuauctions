<?php

namespace Database\Factories;

use App\Models\Lot;
use App\Models\VehicleDetail;
use App\Services\CurbWeightLookup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A lot at auction, vehicle by default.
 *
 * A vehicle is the default because most of the platform is vehicles, and a test
 * about bidding or freight should not have to say so. A goods lot is one call
 * to `->goods()` away.
 *
 * The vehicle columns go to `vehicle_detail` because that is where they now
 * live. A factory that still put them on `lot` would be quietly asserting the
 * old schema, which is exactly the bug this factory exists to prevent.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Lot>
 */
class LotFactory extends Factory
{
    protected $model = Lot::class;

    private const MAKES = [
        ['Toyota', 'Corolla', 1280.0],
        ['Honda', 'Civic', 1270.0],
        ['Ford', 'F150', 2100.0],
        ['Nissan', 'Altima', 1450.0],
    ];

    /**
     * The make and model this build will write into vehicle_detail.
     *
     * Carried on the factory instance rather than parsed back out of the title,
     * so a test that overrides the title still gets a coherent detail row
     * instead of a detail row made of half a string.
     *
     * @var array{make:string, model:string}|null
     */
    private ?array $vehicle = null;

    /** Vehicles. The common case, and the default. */
    public function definition(): array
    {
        [$make, $model, $weight] = self::MAKES[array_rand(self::MAKES)];

        $startsAt = now()->subDay();

        return [
            'id' => 'lot_'.fake()->unique()->regexify('[A-Za-z0-9]{12}'),
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => "{$make} {$model}",
            'description' => null,
            'city' => fake()->randomElement(['Detroit', 'Cleveland', 'Newark', 'Houston']),
            'photos' => [],
            'condition' => fake()->randomElement(['clean_title', 'salvage', 'project']),
            'weight_kg' => $weight,
            'currency_code' => 'usd',
            'starting_bid' => 100_000,
            'reserve_price' => 150_000,
            'bid_increment' => 5_000,
            'status' => 'LIVE',
            'current_bid' => 0,
            'bid_count' => 0,
            'extensions' => 0,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addDays(2),
        ];
    }

    /**
     * Name the vehicle, and keep the title in step with it.
     *
     * Without this a test asking for a Corolla would get a Corolla's weight but
     * a detail row naming whatever the random pick was.
     */
    public function vehicle(string $make, string $model, ?float $weightKg = null): static
    {
        $this->vehicle = ['make' => $make, 'model' => $model];

        $states = ['title' => "{$make} {$model}"];

        if ($weightKg !== null) {
            $states['weight_kg'] = $weightKg;
        }

        return $this->state($states);
    }

    /**
     * A pallet, a bundle, or a single garage item.
     *
     * A headlamp at 13 cents is a lot with its own bid, so this produces the
     * same shape of thing as a car with a different kind and no vehicle detail.
     */
    public function goods(string $unitLabel = 'Pallet', float $weightKg = 610.0): static
    {
        return $this->state([
            'kind' => 'goods',
            'provenance' => 'overstock',
            'title' => $unitLabel,
            'condition' => 'grade_a',
            'weight_kg' => $weightKg,
        ]);
    }

    /** A single garage item, priced in cents small enough to be worth testing. */
    public function garageItem(string $title = 'Bed frame', int $startingBid = 13): static
    {
        return $this->state([
            'kind' => 'goods',
            'provenance' => 'garage_sale',
            'title' => $title,
            'condition' => 'grade_b',
            'weight_kg' => 5.2,
            'starting_bid' => $startingBid,
            'reserve_price' => 0,
            'bid_increment' => 1,
            'current_bid' => $startingBid,
        ]);
    }

    /**
     * Write the vehicle_detail row a vehicle lot needs.
     *
     * After creation rather than in the definition, because the detail row
     * carries a foreign key and `lot` has to exist first.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Lot $lot) {
            if ($lot->kind !== 'vehicle') {
                return;
            }

            if ($lot->vehicleDetail()->exists()) {
                return;
            }

            $vehicle = $this->vehicle ?? [
                'make' => (string) str($lot->title)->before(' '),
                'model' => (string) str($lot->title)->after(' '),
            ];

            VehicleDetail::create([
                'lot_id' => $lot->id,
                'vin' => 'VIN'.strtoupper(fake()->lexify('??????')),
                'year' => fake()->numberBetween(2005, 2024),
                'make' => $vehicle['make'],
                'model' => $vehicle['model'],
                'trim' => fake()->randomElement(['LE', 'SE', 'XLE']),
                'odometer' => fake()->randomElement([null, 44_000, 88_000, 132_000]),
                'damage_primary' => fake()->randomElement([null, 'front', 'rear', 'side']),
            ]);

            // Weigh it now the detail row exists. This used to be a `creating`
            // hook on Lot, which read the make and model straight off the lot.
            // It cannot work any more: those columns moved to a table with a
            // foreign key to lot, so at creating time the row is not there yet.
            app(CurbWeightLookup::class)->applyTo($lot);
        });
    }
}