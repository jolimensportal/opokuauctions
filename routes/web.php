<?php

use App\Http\Controllers\Admin\ConsoleLogoutController;
use App\Http\Controllers\Auth\BuyerAuthController;
use App\Http\Controllers\Shop\CurrencyController;
use App\Http\Controllers\Shop\CustomerFreightController;
use App\Http\Controllers\Shop\OrderController;
use App\Http\Controllers\Shop\QuoteController;
use App\Http\Controllers\Shop\SenderQuoteController;
use App\Http\Controllers\Shop\ShopController;
use App\Http\Controllers\Shop\WalletController;
use App\Http\Controllers\Webhooks\PaystackWebhookController;
use App\Http\Controllers\Webhooks\WhatsAppInboundController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

/*
| Buyer-facing routes. Everything here is the shop; the operator console lives
| behind /admin and shares none of this chrome.
*/

Route::get('/', [ShopController::class, 'home'])->name('home');

/*
| The sender quote. Deliberately public: a first-time sender must reach a price
| without an account, or the rate card is a library nothing can reach.
*/
Route::get('/send', [SenderQuoteController::class, 'create'])->name('send.create');
Route::post('/send', [SenderQuoteController::class, 'store'])->name('send.store');
Route::post('/send/reserve', [SenderQuoteController::class, 'reserve'])->name('send.reserve');

Route::get('/lots', [ShopController::class, 'lots'])->name('lots.index');
Route::get('/lots/{lot}', [ShopController::class, 'lot'])->name('lots.show');

// Freight estimate for the collect-it-yourself toggle on the lot page. Public
// because the page is public: a buyer must be able to see what collection costs
// before deciding whether to create an account. Never writes anything.
Route::get('/lots/{lot}/freight-estimate', [QuoteController::class, 'show'])
    ->name('lots.freight-estimate');

// Currency switch. POST plus a redirect-back: a GET link would let a crawler or
// a prefetcher silently change a visitor's displayed currency.
Route::post('/currency', [CurrencyController::class, 'update'])->name('currency.update');

Route::view('/how-it-works', 'shop.how-it-works')->name('how-it-works');
Route::view('/fees', 'shop.fees')->name('fees');

// Everything below needs a signed-in buyer.
//
// The guard is named on both groups rather than left to the default. The
// console signs in on its own guard and switches the default guard while it
// runs; a shop that authenticated with "whichever guard was last set" would
// answer for the wrong person.
Route::middleware(['auth:web'])->group(function () {
    Route::get('/wallet', [WalletController::class, 'show'])->name('wallet');
    Route::post('/wallet/deposit', [WalletController::class, 'deposit'])->name('wallet.deposit');
    Route::post('/lots/{lot}/bid', [WalletController::class, 'bid'])->name('lots.bid');

    Route::get('/account', [OrderController::class, 'index'])->name('dashboard');
    Route::get('/account/purchases', [OrderController::class, 'purchases'])->name('orders.index');
    Route::get('/account/purchases/{shipment}', [OrderController::class, 'show'])
        ->name('orders.show');

    // The customer portal: a separate application with its own chrome, not a
    // page of the shop wearing the shop's header and footer.
    Route::get('/account/freight', CustomerFreightController::class)->name('freight');

    // POST, never GET. A GET logout is a link a crawler, a prefetcher or an
    // email client can fire without the buyer ever asking for it.
    Route::post('/logout', [BuyerAuthController::class, 'logout'])->name('logout');
});

// Guest-only, so a signed-in buyer hitting /login lands on their account rather
// than on a login form they do not need.
Route::middleware(['guest:web'])->group(function () {
    Route::get('/login', [BuyerAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [BuyerAuthController::class, 'login'])->name('login.store');
    Route::get('/register', [BuyerAuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [BuyerAuthController::class, 'register'])->name('register.store');
});

/*
| Signing out of the operator console.
|
| This deliberately replaces Filament's own logout route. Laravel keeps one
| route per method and URI, and the app's routes are loaded after the
| package's, so this one is the route that answers. Filament's invalidates
| the whole session, which would sign the buyer out of the shop on the same
| browser; this one drops only the operator's session key.
|
| It keeps Filament's route name so the console's own sign-out link still
| resolves to it.
*/
Route::post('/admin/logout', ConsoleLogoutController::class)
    ->name('filament.admin.auth.logout');

Route::prefix('webhooks')->name('webhooks.')->group(function (): void {
    // Both are called by a machine with no session and no CSRF token. Both
    // verify on the request itself — a webhook route excluded from CSRF
    // without a signature check is an endpoint anyone can post to.
    Route::post('paystack', [PaystackWebhookController::class, 'handle'])
        ->name('paystack')
        ->withoutMiddleware([VerifyCsrfToken::class]);

    Route::post('whatsapp', [WhatsAppInboundController::class, 'handle'])
        ->name('whatsapp')
        ->withoutMiddleware([VerifyCsrfToken::class]);
});
