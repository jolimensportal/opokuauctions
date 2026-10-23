<?php

namespace Tests\Feature;

use App\Models\Lot;
use App\Services\LotsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseExpiredLotsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function lot(array $overrides = []): Lot
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
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->addDay(),
        ], $overrides));

        return $lot->fresh();
    }

    private function bidThenExpire(Lot $lot, string $bidderId = '7', int $amount = 195_000): Lot
    {
        app(LotsService::class)->placeBid([
            'lot_id' => $lot->id,
            'bidder_id' => $bidderId,
            'bidder_email' => "{$bidderId}@example.test",
            'amount' => $amount,
        ]);

        $lot->update(['ends_at' => now()->subMinute()]);

        return $lot->fresh();
    }

    public function test_an_expired_auction_with_a_winner_is_closed_as_sold(): void
    {
        $lot = $this->bidThenExpire($this->lot());

        $this->artisan('lots:close-expired')
            ->expectsOutputToContain('sold 1')
            ->assertSuccessful();

        $this->assertSame('SOLD', $lot->fresh()->status);
    }

    public function test_a_live_auction_is_left_alone(): void
    {
        $lot = $this->lot(['ends_at' => now()->addDay()]);

        $this->artisan('lots:close-expired')
            ->expectsOutputToContain('sold 0')
            ->assertSuccessful();

        $this->assertSame('LIVE', $lot->fresh()->status);
    }

    public function test_a_lot_with_a_bid_but_no_bidder_is_reported_and_left_open(): void
    {
        $lot = $this->lot([
            'current_bid' => 195_000,
            'bid_count' => 4,
            'ends_at' => now()->subMinute(),
        ]);

        $this->artisan('lots:close-expired')
            ->expectsOutputToContain('needs review 1')
            ->assertSuccessful();

        $this->assertSame('LIVE', $lot->fresh()->status);
    }

    public function test_running_twice_changes_nothing(): void
    {
        $lot = $this->bidThenExpire($this->lot());

        $this->artisan('lots:close-expired')->assertSuccessful();
        $this->artisan('lots:close-expired')
            ->expectsOutputToContain('sold 0')
            ->assertSuccessful();

        $this->assertSame('SOLD', $lot->fresh()->status);
    }
}
