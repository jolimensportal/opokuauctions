<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Lot;
use App\Services\LotsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class LotsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function liveLot(array $overrides = []): Lot
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

    public function test_a_bid_is_recorded_and_becomes_the_leading_bid(): void
    {
        $lot = $this->liveLot();

        $result = app(LotsService::class)->placeBid([
            'lot_id' => $lot->id,
            'bidder_id' => '7',
            'bidder_email' => 'buyer@example.test',
            'amount' => 145_000,
        ]);

        $this->assertSame(145_000, $result['lot']['current_bid']);
        $this->assertSame(1, $result['lot']['bid_count']);
        $this->assertSame('7', $result['lot']['highest_bidder_id']);

        $this->assertSame(1, Bid::where('lot_id', $lot->id)->count());
        $this->assertSame('ACCEPTED', Bid::where('lot_id', $lot->id)->first()->status);
    }

    public function test_a_higher_bid_demotes_the_previous_leader_but_keeps_the_record(): void
    {
        $lot = $this->liveLot();
        $service = app(LotsService::class);

        $service->placeBid([
            'lot_id' => $lot->id, 'bidder_id' => '7',
            'bidder_email' => 'seven@example.test', 'amount' => 145_000,
        ]);
        $service->placeBid([
            'lot_id' => $lot->id, 'bidder_id' => '8',
            'bidder_email' => 'eight@example.test', 'amount' => 150_000,
        ]);

        $bids = Bid::where('lot_id', $lot->id)->orderBy('created_at')->get();

        $this->assertCount(2, $bids);
        $this->assertSame('OUTBID', $bids[0]->status);
        $this->assertSame('ACCEPTED', $bids[1]->status);
        $this->assertSame('8', $lot->fresh()->highest_bidder_id);
    }

    public function test_a_closed_lot_refuses_bids(): void
    {
        $lot = $this->liveLot(['status' => 'SOLD']);

        $this->expectException(RuntimeException::class);

        app(LotsService::class)->placeBid([
            'lot_id' => $lot->id, 'bidder_id' => '7',
            'bidder_email' => 'seven@example.test', 'amount' => 145_000,
        ]);
    }

    public function test_an_expired_auction_with_a_reserve_met_bid_is_sold_to_the_leader(): void
    {
        $lot = $this->liveLot();
        app(LotsService::class)->placeBid([
            'lot_id' => $lot->id, 'bidder_id' => '7',
            'bidder_email' => 'seven@example.test', 'amount' => 195_000,
        ]);
        $lot->update(['ends_at' => now()->subMinute()]);

        $result = app(LotsService::class)->finalizeLot($lot->id);

        $this->assertSame('SOLD', $result['status']);
        $this->assertSame('7', $result['winner_id']);
        $this->assertSame('SOLD', $lot->fresh()->status);
    }

    public function test_an_expired_auction_below_the_reserve_is_not_sold(): void
    {
        $lot = $this->liveLot(['ends_at' => now()->subMinute()]);

        $result = app(LotsService::class)->finalizeLot($lot->id);

        $this->assertSame('RESERVE_NOT_MET', $result['status']);
        $this->assertNull($result['winner_id']);
    }

    public function test_an_auction_that_has_not_expired_cannot_be_closed(): void
    {
        $lot = $this->liveLot();

        $this->expectException(RuntimeException::class);

        app(LotsService::class)->finalizeLot($lot->id);
    }

    public function test_closing_twice_changes_nothing(): void
    {
        $lot = $this->liveLot();
        app(LotsService::class)->placeBid([
            'lot_id' => $lot->id, 'bidder_id' => '7',
            'bidder_email' => 'seven@example.test', 'amount' => 195_000,
        ]);
        $lot->update(['ends_at' => now()->subMinute()]);

        app(LotsService::class)->finalizeLot($lot->id);
        $second = app(LotsService::class)->finalizeLot($lot->id);

        $this->assertSame('SOLD', $second['status']);
        $this->assertNull($second['winner_id']);
        $this->assertSame('SOLD', $lot->fresh()->status);
    }

    public function test_a_lot_with_a_reserve_met_bid_but_no_bidder_is_never_sold(): void
    {
        // The demo data used to look like this: a bid count and a current bid
        // with no bid row and no bidder. Selling it would record a sale to
        // nobody, and the money panels would then chase a customer who does
        // not exist.
        $lot = $this->liveLot([
            'ends_at' => now()->subMinute(),
            'current_bid' => 195_000,
            'bid_count' => 4,
            'highest_bidder_id' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no recorded bidder');

        app(LotsService::class)->finalizeLot($lot->id);
    }
}
