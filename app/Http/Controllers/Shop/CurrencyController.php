<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Services\DisplayCurrency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switching between USD and GHS.
 *
 * Display only. Settlement stays in USD and the rate a buyer pays is the one
 * locked at their deposit, so flipping this on a price card cannot change what
 * anybody owes.
 */
class CurrencyController extends Controller
{
    public function __construct(private readonly DisplayCurrency $currency) {}

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'currency' => ['required', 'in:usd,ghs'],
        ]);

        $this->currency->set($validated['currency']);

        // Back where they were, not to the homepage: a buyer comparing two lots
        // should not lose their place by switching currency.
        return back()->withInput();
    }
}
