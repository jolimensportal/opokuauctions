<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lot;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The corridor board's buckets now live in the consoles that own them.
 *
 * It used to be a page of its own, next to the freight console and saying
 * much the same thing — two freight screens, and the one with the money in it
 * was the harder to find. The buckets were split by what they are about:
 * freight on the freight console, money on the operations console.
 */
class CorridorContentTest extends TestCase
{
    use RefreshDatabase;

    private function ops(): User
    {
        return User::factory()->create(['role' => Role::Ops]);
    }

    private function soldLot(string $id, bool $paid): Lot
    {
        return Lot::create([
            'id' => $id,
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => $paid ? 'Mazda CX-5' : 'Kia Sportage',
            'city' => 'Detroit',
            'condition' => 'clean_title',
            'currency_code' => 'usd',
            'starting_bid' => 100000,
            'reserve_price' => 500000,
            'bid_increment' => 25000,
            'deposit_bps' => 2000,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'status' => 'SOLD',
            'current_bid' => 625000,
            'highest_bidder_id' => 'b1',
            'bid_count' => 5,
            'balance_due_usd' => $paid ? 0 : 625000,
            'balance_settled_at' => $paid ? now() : null,
        ]);
    }

    public function test_an_unpaid_sale_is_money_at_risk_on_the_operations_console(): void
    {
        $this->soldLot('lot_unpaid_1', paid: false);

        $this->actingAs($this->ops(), 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Money at risk')
            ->assertSee('Sales won and not paid for');
    }

    public function test_a_paid_lot_with_no_container_is_an_exception_on_the_freight_console(): void
    {
        $this->soldLot('lot_paid_1', paid: true);

        $this->actingAs($this->ops(), 'admin')
            ->get('/admin/logistics')
            ->assertOk()
            ->assertSee('Mazda CX-5')
            ->assertSee('paid for and on no container');
    }

    public function test_reconciliation_drift_is_surfaced_on_the_operations_console(): void
    {
        $wallet = Wallet::create([
            'id' => 'wlt_drift',
            'user_id' => User::factory()->create(['role' => Role::Buyer])->id,
            'currency_code' => 'usd',
            'balance_available' => 500000,
            'balance_held' => 0,
            'version' => 1,
        ]);

        $this->actingAs($this->ops(), 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Wallets that disagree with their own ledger');
    }

    public function test_a_healthy_ledger_shows_no_drift(): void
    {
        Wallet::create([
            'id' => 'wlt_ok',
            'user_id' => User::factory()->create(['role' => Role::Buyer])->id,
            'currency_code' => 'usd',
            'balance_available' => 0,
            'balance_held' => 0,
            'version' => 1,
        ]);

        $this->actingAs($this->ops(), 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('The numbers above cannot be trusted');
    }

    public function test_buyers_never_reach_either_console(): void
    {
        $buyer = User::factory()->create(['role' => Role::Buyer]);

        $this->actingAs($buyer, 'admin')->get('/admin')->assertForbidden();
        $this->actingAs($buyer, 'admin')->get('/admin/logistics')->assertForbidden();
    }
}
