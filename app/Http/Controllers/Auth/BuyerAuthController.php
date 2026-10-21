<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Buyer registration and sign-in.
 *
 * Separate from Filament on purpose. Filament is the operator console: dense,
 * keyboard-driven, and it should not be the first thing a Ghanaian buyer ever
 * sees. A buyer account is a phone number, a password, and a name.
 */
class BuyerAuthController extends Controller
{
    public function showLogin(): View
    {
        return view('shop.auth.login');
    }

    public function showRegister(): View
    {
        return view('shop.auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            // Ghanaian numbers are entered every which way; 9-15 digits after
            // normalisation covers +233, 0 and the raw local form.
            'phone' => ['required', 'string', 'max:32', 'regex:/^[0-9+\s()-]{9,32}$/'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
            // Explicit consent, not a pre-ticked box. A pre-ticked marketing
            // consent is not consent.
            'terms' => ['accepted'],
        ], [
            'phone.regex' => 'Enter a phone number we can reach you on, digits only.',
            'terms.accepted' => 'You need to accept the terms to bid.',
        ]);

        $user = DB::transaction(function () use ($validated) {
            return User::create([
                'name' => $validated['name'],
                'email' => Str::lower($validated['email']),
                'phone' => $this->normalisePhone($validated['phone']),
                'password' => $validated['password'],
                // New accounts are buyers, never operators. Role escalation must
                // go through an explicit admin action, so it cannot ride along
                // with a public registration form.
                'role' => 'buyer',
            ]);
        });

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'))
            ->with('status', 'Account created. Fund your wallet to start bidding.');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string'],
        ]);

        // Rate limit the whole login endpoint, not just failures. Credential
        // stuffing is cheap; each guess should cost the attacker time.
        $key = 'login:'.Str::lower($validated['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Too many attempts. Wait a minute and try again.']);
        }

        if (! Auth::attempt([
            'email' => Str::lower($validated['email']),
            'password' => $validated['password'],
        ], $request->boolean('remember'))) {
            // Deliberately vague: do not reveal whether the address exists.
            RateLimiter::hit($key, 60);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Those details did not match an account.']);
        }

        RateLimiter::clear($key);

        // A session id fixed before login can be ridden afterwards, so rotate it.
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Sign the buyer out of the shop.
     *
     * There was no storefront sign-out at all, and the session cookie is
     * HttpOnly, so nothing on the page could clear it either. A buyer who
     * signed in on a shared machine could not end the session.
     *
     * The operator console is a separate guard inside the same session, so
     * this drops the buyer's key and rotates the session id rather than
     * invalidating everything: signing out of the shop must not sign the
     * operator out.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->regenerate();

        return redirect()->route('home')
            ->with('status', 'You are signed out.');
    }

    /**
     * Ghana numbers arrive as 0244 123 456, 233244123456, +233 244 123 456.
     * Store one shape so WhatsApp messaging and phone lookup both work.
     */
    private function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '233') && strlen($digits) === 12) {
            return '0'.substr($digits, 3);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return $digits;
        }

        return $digits;
    }
}
