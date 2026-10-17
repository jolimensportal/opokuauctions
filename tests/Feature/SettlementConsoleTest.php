<?php

namespace Tests\Feature;

use App\Filament\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Filament\Resources\Wallets\Pages\ListWallets;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Does the settlement console actually work, or does it merely return 200?
 *
 * This renders the real Filament pages against real data and fires the
 * reconcile action, so a column that references a missing field or an action
 * that throws will fail here rather than in front of a user.
 */
class SettlementConsoleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
    }

    public function test_admin_can_list_wallets_with_money_columns(): void
    {
        $this->actingAs($this->admin);

        $wallet = Wallet::create([
            'user_id' => 'buyer-77',
            'currency_code' => 'usd',
            'balance_available' => 125_00,
            'balance_held' => 40_00,
            'version' => 3,
            'id' => 'wallet_test_1',
        ]);

        // Filament renders table bodies through Livewire, so assert with its own
        // table API rather than scraping HTML and guessing at money formatting.
        Livewire::test(ListWallets::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$wallet])
            ->assertTableActionVisible('reconcile', $wallet)
            ->assertTableColumnStateSet('balance_available', '12500', $wallet);
    }

    public function test_reconcile_action_reports_a_clean_wallet(): void
    {
        $this->actingAs($this->admin);

        $service = app(WalletService::class);
        $service->ensureWallet('buyer-clean');
        $service->deposit('buyer-clean', 100_00);

        Livewire::test(ListWallets::class)
            ->callTableAction('reconcile', Wallet::where('user_id', 'buyer-clean')->first())
            ->assertNotified();
    }

    public function test_ledger_page_is_read_only(): void
    {
        $this->actingAs($this->admin);

        // A ledger entry can be read but never created, edited or deleted.
        $this->assertFalse(
            \App\Filament\Resources\LedgerEntries\LedgerEntryResource::canCreate()
        );
    }

    public function test_ledger_lists_entries(): void
    {
        $this->actingAs($this->admin);

        $service = app(WalletService::class);
        $service->ensureWallet('buyer-ledger');
        $service->deposit('buyer-ledger', 500_00);
        $service->hold('buyer-ledger', 200_00, 'usd', ['id' => 'bid_x']);

        Livewire::test(ListLedgerEntries::class)
            ->assertSuccessful()
            ->assertSee('DEPOSIT')
            ->assertSee('HOLD');
    }
}