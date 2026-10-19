<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lot;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every storefront page, for a customer who actually has a balance.
 *
 * The nav renders the wallet balance. That line was written as a single
 * expression that read the variable inside the same ternary that assigned it,
 * so it only worked when the wallet did not exist — the false branch never ran
 * the broken line. A signed-in customer with a USD balance therefore got a 500
 * on every page of the site, and no test caught it because none of them signed
 * in somebody who had money.
 *
 * A customer with a zero balance and a customer with money are the same code
 * path. This makes that true of the tests as well.
 */
class StorefrontSmokeTest extends TestCase
{
    use RefreshDatabase;

    /** Pages anyone may see. /account and /wallet are behind auth. */
    public static function publicPageProvider(): array
    {
        return [
            'home' => ['/'],
            'lot board' => ['/lots'],
            'goods board' => ['/lots?kind=goods'],
            'fees' => ['/fees'],
            'how it works' => ['/how-it-works'],
            'send' => ['/send'],
        ];
    }

    /** The whole site, for somebody signed in. */
    public static function memberPageProvider(): array
    {
        return [
            'home' => ['/'],
            'lot board' => ['/lots'],
            'goods board' => ['/lots?kind=goods'],
            'account' => ['/account'],
            'wallet' => ['/wallet'],
            'purchases' => ['/account/purchases'],
            'fees' => ['/fees'],
            'send' => ['/send'],
        ];
    }

    private function buyerWithBalance(): User
    {
        $user = User::factory()->create(['role' => Role::Buyer]);

        Wallet::create([
            'id' => 'wallet_smoke_'.$user->id,
            'user_id' => $user->id,
            'currency_code' => 'usd',
            'balance_available' => 250_00,
        ]);

        return $user->refresh();
    }

    #[DataProvider('memberPageProvider')]
    public function test_a_signed_in_customer_with_a_balance_can_load_every_page(string $path): void
    {
        $this->actingAs($this->buyerWithBalance())
            ->get($path)
            ->assertOk();
    }

    #[DataProvider('publicPageProvider')]
    public function test_a_guest_can_load_every_public_page(string $path): void
    {
        $this->get($path)->assertOk();
    }

    #[DataProvider('memberPageProvider')]
    public function test_a_lot_page_renders_for_a_real_lot(string $path): void
    {
        Lot::factory()->vehicle('Toyota', 'Corolla')->create(['status' => 'LIVE']);

        $this->actingAs($this->buyerWithBalance())->get($path)->assertOk();
    }

    public function test_a_lot_detail_page_renders(): void
    {
        $lot = Lot::factory()->vehicle('Toyota', 'Corolla')->create(['status' => 'LIVE']);

        $this->get('/lots/'.$lot->id)->assertOk();
    }

    public function test_the_nav_shows_the_real_balance_and_not_a_zero(): void
    {
        $this->actingAs($this->buyerWithBalance())
            ->get('/')
            ->assertOk()
            ->assertSee('USD 250.00')
            // A signed-in customer with money must never be shown a zero
            // balance — that reads as "we have lost your money".
            ->assertDontSee('USD 0.00');
    }

    public function test_a_customer_with_no_wallet_still_loads_the_nav(): void
    {
        // The other half of the same bug: a missing wallet used to be the only
        // case that worked, so it must keep working too.
        $this->actingAs(User::factory()->create(['role' => Role::Buyer]))
            ->get('/')
            ->assertOk();
    }
}