<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Bid;
use App\Models\FreightRate;
use App\Models\Lot;
use App\Models\OriginZone;
use App\Models\RateCard;
use App\Models\RateCardRow;
use App\Models\User;
use App\Models\Wallet;
use App\Services\OperationsOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the auction side of the business looks like today.
 *
 * The counters and the queue are derived, so they cannot go stale. A page
 * with the findings typed into it says what was true the day it was written.
 */
class OperationsOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function lot(string $id, string $status = 'LIVE', bool $ended = false): Lot
    {
        return Lot::create([
            'id' => $id,
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => 'Vehicle '.$id,
            'city' => 'Newark',
            'photos' => [],
            'currency_code' => 'usd',
            'starting_bid' => 100000,
            'reserve_price' => 0,
            'bid_increment' => 1000,
            'starts_at' => now()->subDay(),
            'ends_at' => $ended ? now()->subHour() : now()->addDay(),
            'current_bid' => 200000,
            'status' => $status,
            'bid_count' => 0,
            'extensions' => 0,
            'deposit_bps' => 2000,
        ]);
    }

    public function test_it_counts_the_lots_on_sale(): void
    {
        $this->lot('lot_a');
        $this->lot('lot_b');
        $this->lot('lot_c', 'UPCOMING');

        $summary = app(OperationsOverview::class)->summary();

        $this->assertSame(2, $summary['lotsLive']);
        $this->assertSame(0, $summary['openBids']);
        $this->assertSame(0, $summary['moneyOwedCents']);
        $this->assertSame(0, $summary['heldOrDisputed']);
    }

    public function test_it_counts_the_open_bids(): void
    {
        $lot = $this->lot('lot_a');

        Bid::create([
            'id' => 'bid_a',
            'lot_id' => $lot->id,
            'bidder_id' => User::factory()->create(['role' => Role::Buyer])->id,
            'bidder_email' => 'buyer@example.test',
            'amount' => 200000,
            'currency_code' => 'usd',
            'status' => 'LEADING',
            'caused_extension' => false,
            'idempotency_key' => 'key-a',
        ]);

        $summary = app(OperationsOverview::class)->summary();

        $this->assertSame(1, $summary['openBids']);
    }

    public function test_it_sums_the_money_owed_to_us(): void
    {
        $lot = $this->lot('lot_a');
        $lot->update(['balance_due_usd' => 45000, 'highest_bidder_id' => 'someone']);

        $summary = app(OperationsOverview::class)->summary();

        $this->assertSame(45000, $summary['moneyOwedCents']);
    }

    public function test_it_counts_the_funds_held(): void
    {
        Wallet::create([
            'id' => 'wlt_a',
            'user_id' => User::factory()->create(['role' => Role::Buyer])->id,
            'currency_code' => 'usd',
            'balance_available' => 0,
            'balance_held' => 12000,
            'version' => 1,
        ]);

        $summary = app(OperationsOverview::class)->summary();

        $this->assertSame(1, $summary['heldOrDisputed']);
    }

    /**
     * The rate card and the forwarder rate, at the numbers the demo ships:
     * the card charges 32 c/kg and the forwarder charges 32 c/kg.
     */
    private function priceCards(): void
    {
        RateCard::create(['id' => 'card_test', 'name' => 'Test card', 'currency' => 'usd']);

        OriginZone::create([
            'id' => 'zone_test',
            'country' => 'us',
            'code' => 'nj-test',
            'name' => 'Test zone',
            'active' => true,
        ]);

        FreightRate::create([
            'id' => 'fr_test',
            'lane' => 'na-tema',
            'usd_per_kg' => 0.32,
            'min_charge_cents' => 25000,
            'insurance_cents' => 10700,
            'pickup_cents' => 15000,
            'active' => true,
        ]);

        RateCardRow::create([
            'id' => 'row_test',
            'rate_card_id' => 'card_test',
            'origin_zone_id' => 'zone_test',
            'destination' => 'Tema',
            'mode' => 'air',
            'unit' => 'parcel',
            'intake_method' => 'dropoff',
            'service_level' => 'standard',
            'divisor' => 5000,
            'per_kg_cents' => 32,
            'min_charge_cents' => 2500,
            'effective_from' => now()->subMonth(),
        ]);

        RateCardRow::create([
            'id' => 'row_pickup_test',
            'rate_card_id' => 'card_test',
            'origin_zone_id' => 'zone_test',
            'destination' => 'Tema',
            'mode' => 'air',
            'unit' => 'parcel',
            'intake_method' => 'partner_pickup',
            'service_level' => 'standard',
            'divisor' => 5000,
            'min_charge_cents' => 15000,
            'effective_from' => now()->subMonth(),
        ]);
    }

    public function test_it_flags_the_rate_card_billing_at_or_below_cost(): void
    {
        $this->priceCards();

        $actions = app(OperationsOverview::class)->actions();

        $kinds = array_column($actions, 'kind');

        $this->assertContains('rate_card_at_cost', $kinds);
    }

    public function test_it_does_not_flag_a_rate_card_that_charges_more_than_cost(): void
    {
        $this->priceCards();

        RateCardRow::query()->where('id', 'row_test')->update(['per_kg_cents' => 90]);

        $actions = app(OperationsOverview::class)->actions();

        $this->assertNotContains('rate_card_at_cost', array_column($actions, 'kind'));
    }

    public function test_it_flags_a_pickup_row_with_no_per_kilo_charge(): void
    {
        $this->priceCards();

        $actions = app(OperationsOverview::class)->actions();

        $this->assertContains('pickup_without_freight', array_column($actions, 'kind'));
    }

    public function test_it_flags_lots_that_closed_and_are_still_open_for_bids(): void
    {
        $this->lot('lot_open');
        $this->lot('lot_closed', 'LIVE', ended: true);

        $actions = app(OperationsOverview::class)->actions();

        $closed = collect($actions)->firstWhere('kind', 'closed_lot_still_open');

        $this->assertNotNull($closed);
        $this->assertStringContainsString('1', (string) $closed['tag']);
    }

    public function test_it_lists_the_activity_newest_first(): void
    {
        $this->lot('lot_a', 'LIVE', ended: true);

        $activity = app(OperationsOverview::class)->activity();

        $this->assertNotEmpty($activity);

        $times = array_column($activity, 'at');
        $sorted = $times;
        rsort($sorted);

        $this->assertSame($sorted, $times);
    }
}
