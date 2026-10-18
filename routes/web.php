<?php

use App\Http\Controllers\Shop\CurrencyController;
use App\Http\Controllers\Shop\SenderQuoteController;
use App\Http\Controllers\Shop\ShopController;
use App\Http\Controllers\Shop\WalletController;
use App\Http\Controllers\Shop\OrderController;
use App\Http\Controllers\Shop\QuoteController;
use App\Http\Controllers\Auth\BuyerAuthController;
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
Route::middleware(['auth'])->group(function () {
    Route::get('/wallet', [WalletController::class, 'show'])->name('wallet');
    Route::post('/wallet/deposit', [WalletController::class, 'deposit'])->name('wallet.deposit');
    Route::post('/lots/{lot}/bid', [WalletController::class, 'bid'])->name('lots.bid');

    Route::get('/account', [OrderController::class, 'index'])->name('dashboard');
    Route::get('/account/purchases', [OrderController::class, 'purchases'])->name('orders.index');
    Route::get('/account/purchases/{shipment}', [OrderController::class, 'show'])
        ->name('orders.show');
});

// Guest-only, so a signed-in buyer hitting /login lands on their account rather
// than on a login form they do not need.
Route::middleware(['guest'])->group(function () {
    Route::get('/login', [BuyerAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [BuyerAuthController::class, 'login'])->name('login.store');
    Route::get('/register', [BuyerAuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [BuyerAuthController::class, 'register'])->name('register.store');
});