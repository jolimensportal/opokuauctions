<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * A buyer has to be able to sign out of the shop.
 *
 * There was no storefront sign-out at all. The only logout in the application
 * was Filament's, which lives behind /admin — so a buyer who signed in could
 * never end the session on the machine they signed in on, and the cookie is
 * HttpOnly, so no amount of client-side code could clear it either.
 */
class StorefrontSignOutTest extends TestCase
{
    use RefreshDatabase;

    /** Sign in the way a buyer does, so the session really carries the login. */
    private function signIn(): User
    {
        $buyer = User::factory()->create(['role' => Role::Buyer]);

        $this->post('/login', [
            'email' => $buyer->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        // Re-read the session on the next request instead of reusing the
        // guard instance this request left in memory.
        Auth::forgetGuards();

        return $buyer;
    }

    public function test_a_signed_in_buyer_can_sign_out(): void
    {
        $this->signIn();

        $this->post('/logout')->assertRedirect(route('home'));
        Auth::forgetGuards();

        $this->get('/account')->assertRedirect(route('login'));
    }

    public function test_signing_out_is_a_post_not_a_get(): void
    {
        $this->signIn();

        // A GET logout is a link a crawler, a prefetcher or an email client
        // can fire without the buyer ever asking for it.
        $this->get('/logout')->assertMethodNotAllowed();

        $this->get('/account')->assertOk();
    }

    public function test_the_account_page_offers_a_sign_out_control(): void
    {
        $this->signIn();

        $this->get('/account')
            ->assertOk()
            ->assertSee('Sign out', false)
            ->assertSee(route('logout'), false);
    }
}
