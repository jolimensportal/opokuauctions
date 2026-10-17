<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The role split only matters if it is actually enforced, so these assert the
 * refusals rather than the grants. A permission test that only checks who CAN
 * do something passes even when nobody is restricted.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_buyer_cannot_access_the_admin_panel(): void
    {
        $this->actingAs($this->user(Role::Buyer));

        $this->get('/admin')->assertForbidden();
    }

    public function test_ops_and_owner_can_access_the_admin_panel(): void
    {
        $this->actingAs($this->user(Role::Ops));
        $this->get('/admin')->assertOk();

        $this->actingAs($this->user(Role::Owner));
        $this->get('/admin')->assertOk();
    }

    public function test_only_owner_may_release_disputed_funds(): void
    {
        $ops = $this->user(Role::Ops);
        $owner = $this->user(Role::Owner);

        $this->assertFalse($ops->role->canReleaseDisputedFunds());
        $this->assertTrue($owner->role->canReleaseDisputedFunds());
    }

    public function test_buyer_cannot_operate_the_corridor(): void
    {
        $buyer = $this->user(Role::Buyer);
        $ops = $this->user(Role::Ops);

        $this->assertFalse($buyer->role->canOperate());
        $this->assertTrue($ops->role->canOperate());
    }

    public function test_role_middleware_refuses_the_wrong_role(): void
    {
        $ops = $this->user(Role::Ops);
        $owner = $this->user(Role::Owner);

        // A route gated to owners only.
        \Route::middleware(['web', 'auth', 'role:owner'])
            ->get('/_test/owner-only', fn () => response('ok'));

        $this->actingAs($ops)->get('/_test/owner-only')->assertForbidden();
        $this->actingAs($owner)->get('/_test/owner-only')->assertOk();
    }

    public function test_buyer_wallet_is_scoped_to_their_own_currency(): void
    {
        $buyer = $this->user(Role::Buyer);
        $walletService = app(WalletService::class);
        $walletService->ensureWallet($buyer->id, 'usd');
        $walletService->ensureWallet($buyer->id, 'ghs');

        $this->assertNotNull($buyer->wallet('usd'));
        $this->assertNotNull($buyer->wallet('ghs'));
        $this->assertNull($buyer->wallet('cad'), 'a wallet should not exist in an untouched currency');
    }

    public function test_balances_are_isolated_between_buyers(): void
    {
        $alice = $this->user(Role::Buyer);
        $bob = $this->user(Role::Buyer);
        $walletService = app(WalletService::class);

        $walletService->ensureWallet($alice->id, 'usd');
        $walletService->deposit($alice->id, 50000, 'usd');

        $this->assertSame(50000, $alice->wallet('usd')->balance_available);
        $this->assertSame(0, $bob->wallet('usd')?->balance_available ?? 0);
    }
}