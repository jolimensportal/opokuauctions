<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Services\CustomerFreight;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The customer's own freight.
 *
 * A separate application from the shop: its own top bar, its own rail, and
 * no storefront header or footer. A buyer who signs in should land in
 * something that looks like the same company, not like a page of the shop.
 */
class CustomerFreightController extends Controller
{
    public function __invoke(Request $request, CustomerFreight $freight): View
    {
        return view('portal.freight', $freight->for($request->user()));
    }
}
