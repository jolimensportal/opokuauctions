<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\CurbWeightLookup;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Roles and operator accounts first: DemoLotSeeder and the shop depend
        // on nothing here, but the Filament console is useless without them.
        $this->call([
            RoleSeeder::class,
        ]);

        // Reference data for the freight quote, before any lot exists. A lot
        // takes its weight from this table as it is created, so seeding it
        // afterwards would leave every demo lot unweighed and every lot page
        // showing an empty freight card.
        $this->call([
            CurbWeightSeeder::class,
            FreightRateSeeder::class,
            LogisticsRateSeeder::class,
        ]);

        $this->call([
            DemoLotSeeder::class,
            DemoGoodsSeeder::class,
            DemoInventorySeeder::class,
            DemoShipmentSeeder::class,
            // Last: it awards one of the container's cars to the buyer, so it
            // needs the container and the buyer to exist first.
            DemoBuyerFreightSeeder::class,
            // Last of all: the bid history is written from the figures the
            // inventory seeders painted, and it must not change who won
            // lot_demo_02 - so it runs after that car has been awarded.
            DemoBidSeeder::class,
        ]);

        // This seeder mutes model events, so the Lot creating hook that weighs a
        // new lot never fires here. The pass has to be explicit or every demo
        // lot ships unweighed. Run by hand on an existing database too:
        // `php artisan lots:weigh`.
        app(CurbWeightLookup::class)->backfill();

        // A buyer for looking at the signed-in side of the shop. Credentials are
        // printed by the seeder so nobody has to guess them, and this password
        // exists only in a local development database.
        User::firstOrCreate(
            ['email' => 'buyer@opokuauctions.test'],
            [
                'name' => 'Ama Boateng',
                'role' => 'buyer',
                'phone' => '0240000000',
                'password' => 'OpokuBuyer!2026',
            ],
        );
    }
}
