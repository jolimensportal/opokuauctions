<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\CorridorDashboard;
use App\Models\Lot;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard is the console. If it renders empty the operator sees nothing,
 * so this asserts the real queues with real rows rather than only checking the
 * page returns 200 — a 200 proves nothing about what was displayed.
 */
class CorridorDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_for_ops(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin');

        $this->get(CorridorDashboard::getUrl(panel: 'admin'))->assertSuccessful();
    }

    public function test_it_shows_an_unpaid_lot_in_needs_money(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin');

        Lot::create([
            'id' => 'lot_unpaid_1', 'kind' => 'vehicle', 'provenance' => 'salvage_auction',
            'title' => 'Kia Sportage',
            'city' => 'Detroit',
            'condition' => 'clean_title',
            'currency_code' => 'usd',
            'starting_bid' => 100000, 'reserve_price' => 500000, 'bid_increment' => 25000,
            'deposit_bps' => 2000,
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay(),
            'status' => 'SOLD', 'current_bid' => 625000, 'highest_bidder_id' => 'b1', 'bid_count' => 5,
        ]);

        $this->get(CorridorDashboard::getUrl(panel: 'admin'))
            ->assertSuccessful()
            ->assertSee('Kia')
            ->assertSee('Needs money');
    }

    public function test_it_shows_a_paid_lot_with_no_container_as_unallocated(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin');

        Lot::create([
            'id' => 'lot_paid_1', 'kind' => 'vehicle', 'provenance' => 'salvage_auction',
            'title' => 'Mazda CX-5',
            'city' => 'Detroit',
            'condition' => 'clean_title',
            'currency_code' => 'usd',
            'starting_bid' => 100000, 'reserve_price' => 500000, 'bid_increment' => 25000,
            'deposit_bps' => 2000, 'balance_settled_at' => now(),
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay(),
            'status' => 'SOLD', 'current_bid' => 700000, 'highest_bidder_id' => 'b2', 'bid_count' => 6,
        ]);

        $this->get(CorridorDashboard::getUrl(panel: 'admin'))
            ->assertSuccessful()
            ->assertSee('Mazda')
            ->assertSee('Unallocated');
    }

    public function test_reconciliation_drift_is_surfaced_on_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin');

        $wallet = app(WalletService::class);
        $wallet->ensureWallet('buyer-tampered');
        $wallet->deposit('buyer-tampered', 100000);

        // Tamper with the cached balance; the ledger stays authoritative.
        Wallet::where('user_id', 'buyer-tampered')
            ->update(['balance_available' => 999999]);

        $this->get(CorridorDashboard::getUrl(panel: 'admin'))
            ->assertSuccessful()
            ->assertSee('disagree with their own ledger')
            ->assertSee('buyer-tampered');
    }

    public function test_a_healthy_ledger_shows_no_drift_banner(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin');

        $wallet = app(WalletService::class);
        $wallet->ensureWallet('buyer-healthy');
        $wallet->deposit('buyer-healthy', 100000);
        $wallet->hold('buyer-healthy', 25000, 'usd', ['id' => 'b1']);

        $this->get(CorridorDashboard::getUrl(panel: 'admin'))
            ->assertSuccessful()
            ->assertDontSee('disagree with their own ledger');
    }

    public function test_buyers_never_reach_the_corridor_dashboard(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Buyer]), 'admin');

        $this->get('/admin/corridor-dashboard')->assertForbidden();
    }
}
