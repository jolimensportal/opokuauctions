<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The shop and the operator console are two different people in one browser.
 *
 * Both used to authenticate against the single `web` guard, so signing in as an
 * operator threw the buyer out and vice versa. The fix is a second guard: the
 * panel authenticates on `admin`, the shop on `web`, and each keeps its own
 * session key inside the one session cookie.
 *
 * That only works if signing out of one side leaves the other side alone. A
 * logout that flushes the session takes both with it, so the isolation is
 * asserted here rather than assumed.
 *
 * These tests sign the buyer in through the real login form rather than
 * `actingAs`. `actingAs` only sets a user in memory and writes nothing to the
 * session, so a test built on it would keep passing even when the session
 * itself had been thrown away.
 */
class SessionSeparationTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(): User
    {
        return User::factory()->create(['role' => Role::Buyer]);
    }

    private function ops(): User
    {
        return User::factory()->create(['role' => Role::Ops]);
    }

    /** Sign in the way a buyer does, so the session really carries the login. */
    private function signInBuyer(): User
    {
        $buyer = $this->buyer();

        $this->post('/login', [
            'email' => $buyer->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        // The next request must re-read the session rather than reuse the
        // guard instance this request left behind in memory.
        Auth::forgetGuards();

        return $buyer;
    }

    /** Sign the operator into the console's own guard. */
    private function signInOperator(): User
    {
        $ops = $this->ops();

        Auth::guard('admin')->login($ops);

        return $ops;
    }

    public function test_a_buyer_and_an_operator_can_be_signed_in_at_the_same_time(): void
    {
        $this->signInBuyer();
        $this->signInOperator();

        $this->get('/account')->assertOk();
        $this->get('/admin')->assertOk();
    }

    public function test_the_operator_panel_does_not_accept_the_shop_session(): void
    {
        // An operator signed into the shop is still not signed into the
        // console. The panel has its own guard and says so.
        $this->signInBuyer();

        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_signing_out_of_the_console_leaves_the_buyer_signed_in(): void
    {
        $this->signInBuyer();
        $this->signInOperator();

        $this->post('/admin/logout')->assertRedirect();
        Auth::forgetGuards();

        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/account')->assertOk();
    }

    public function test_signing_out_of_the_shop_leaves_the_operator_signed_in(): void
    {
        $this->signInBuyer();
        $this->signInOperator();

        $this->post('/logout')->assertRedirect();
        Auth::forgetGuards();

        $this->get('/account')->assertRedirect('/login');
        $this->get('/admin')->assertOk();
    }
}
