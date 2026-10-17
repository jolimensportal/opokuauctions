<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Lot;
use App\Services\Quote;
use App\Services\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The freight estimate behind the collect-it-yourself toggle on a lot page.
 *
 * Thin on purpose, like every other controller here: all the rules are in
 * QuoteService, which is tested without a browser.
 *
 * Two properties are the controller's own responsibility rather than the
 * service's, because they are about HTTP rather than about money:
 *
 *  1. **A lot we cannot price is still a successful request.** The toggle is on
 *     a page the buyer is already reading and is asked on every click; an error
 *     status there reads as the site being broken. The body says
 *     `available: false` and the page renders a sentence instead of a figure.
 *
 *  2. **Nothing is written.** It is a price display, not an order. A row left
 *     behind by an estimate would eventually be mistaken for a charge.
 */
class QuoteController extends Controller
{
    public function __construct(private readonly QuoteService $quotes) {}

    public function show(Request $request, Lot $lot): JsonResponse
    {
        // Absent means bring-yourself: collecting the car is the thing we charge
        // for, so it is never the default the buyer has to opt out of.
        $quote = $this->quotes->forLot($lot, $request->boolean('pickup'));

        return response()->json(
            $quote?->toArray() ?? Quote::unavailablePayload()
        );
    }
}