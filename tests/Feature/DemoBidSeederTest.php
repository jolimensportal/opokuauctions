<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Lot;
use App\Models\User;
use App\Services\WalletService;
use Database\Seeders\DemoBidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoBidSeederTest extends TestCase
{
    use RefreshDatabase;

    private function paintedLot(array $overrides = []): Lot
    {
        $lot = Lot::create(array_merge([
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => '2018 Audi RS4',
            'city' => 'Newark',
            'condition' => 'clean_title',
            'currency_code' => 'usd',
            'starting_bid' => 145_000,
            'reserve_price' => 190_000,
            'bid_increment' => 5_000,
            'status' => 'LIVE',
            'current_bid' => 0,
            'bid_count' => 0,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ], $overrides));

        return $lot->fresh();
    }

    public function test_every_painted_bid_count_becomes_a_real_bid(): void
    {
        $lot = $this->paintedLot();
        $lot->update(['current_bid' => 165_000, 'bid_count' => 4]);

        $this->seed(DemoBidSeeder::class);

        $this->assertSame(4, Bid::where('lot_id', $lot->id)->count());
        $this->assertSame(165_000, $lot->fresh()->current_bid);
        $this->assertNotNull($lot->fresh()->highest_bidder_id);
    }

    public function test_the_bids_rise_and_land_on_the_painted_current_bid(): void
    {
        $lot = $this->paintedLot();
        $lot->update(['current_bid' => 165_000, 'bid_count' => 4]);

        $this->seed(DemoBidSeeder::class);

        // Ordered by amount, not by created_at: the bids are written in the
        // same second, so the timestamps cannot order them.
        $amounts = Bid::where('lot_id', $lot->id)->orderBy('amount')->pluck('amount')->all();

        $this->assertSame([150_000, 155_000, 160_000, 165_000], $amounts);
    }

    public function test_the_bidders_are_real_users_with_money_behind_them(): void
    {
        $lot = $this->paintedLot();
        $lot->update(['current_bid' => 165_000, 'bid_count' => 4]);

        $this->seed(DemoBidSeeder::class);

        $bidder = User::find($lot->fresh()->highest_bidder_id);

        $this->assertNotNull($bidder);
        $this->assertSame('buyer', $bidder->role->value);

        // The money is real: the wallet exists, it has a balance, and it
        // reconciles against its own ledger.
        $wallet = app(WalletService::class)->reconcile((string) $bidder->id, 'usd');

        $this->assertTrue($wallet['ok'], 'the demo wallet drifted against its own ledger');
        $this->assertGreaterThan(0, $wallet['wallet']['available']);
    }

    public function test_a_lot_that_already_names_a_winner_keeps_that_winner(): void
    {
        $winner = User::create([
            'name' => 'The buyer',
            'email' => 'the.buyer@opokuauctions.test',
            'password' => 'opoku-demo-bidder',
            'role' => 'buyer',
        ]);

        $lot = $this->paintedLot();
        $lot->update(['current_bid' => 165_000, 'bid_count' => 4, 'highest_bidder_id' => (string) $winner->id]);

        $this->seed(DemoBidSeeder::class);

        $this->assertSame(4, Bid::where('lot_id', $lot->id)->count());
        $this->assertSame((string) $winner->id, $lot->fresh()->highest_bidder_id);

        // The winner's bid is the one still standing: placeBid demotes every
        // other bid to OUTBID and leaves the leader ACCEPTED.
        $standing = Bid::where('lot_id', $lot->id)->where('status', 'ACCEPTED')->first();

        $this->assertNotNull($standing);
        $this->assertSame((string) $winner->id, $standing->bidder_id);
        $this->assertSame(165_000, $standing->amount);
    }

    public function test_a_painted_count_the_increment_cannot_reach_is_capped(): void
    {
        $lot = $this->paintedLot([
            'starting_bid' => 320_000,
            'reserve_price' => 410_000,
            'bid_increment' => 10_000,
        ]);
        $lot->update(['current_bid' => 385_000, 'bid_count' => 14]);

        $this->seed(DemoBidSeeder::class);

        $fresh = $lot->fresh();

        // Seven bids of $10,000 steps fit between $320,000 and $385,000.
        // Fourteen would have had to land far above the painted price.
        $this->assertSame(7, Bid::where('lot_id', $lot->id)->count());
        $this->assertSame(7, $fresh->bid_count);
        $this->assertSame(385_000, $fresh->current_bid);
    }

    public function test_a_lot_with_no_painted_bids_is_left_alone(): void
    {
        $lot = $this->paintedLot();

        $this->seed(DemoBidSeeder::class);

        $this->assertSame(0, Bid::where('lot_id', $lot->id)->count());
        $this->assertNull($lot->fresh()->highest_bidder_id);
    }

    public function test_running_the_seeder_twice_does_not_double_the_history_or_the_money(): void
    {
        $lot = $this->paintedLot();
        $lot->update(['current_bid' => 165_000, 'bid_count' => 4]);

        $this->seed(DemoBidSeeder::class);
        $this->seed(DemoBidSeeder::class);

        $this->assertSame(4, Bid::where('lot_id', $lot->id)->count());

        $bidder = User::find($lot->fresh()->highest_bidder_id);
        $wallet = app(WalletService::class)->reconcile((string) $bidder->id, 'usd');

        $this->assertTrue($wallet['ok']);
        $this->assertSame(5_000_000, $wallet['wallet']['available']);
    }
}
