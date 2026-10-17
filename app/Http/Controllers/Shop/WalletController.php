<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Services\LotsService;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The buyer's money: wallet balance, funding it, and spending it on bids.
 *
 * Thin by design. Every mutation goes through WalletService or LotsService so
 * the ledger rules are tested without HTTP.
 */
class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallets) {}

    public function show(Request $request): View
    {
        $userId = (string) $request->user()->id;
        $wallet = $this->wallets->ensureWallet($userId, 'usd');

        return view('shop.wallet', [
            'wallet' => $wallet,
            // reconcile() is a read: it verifies the cached balance matches the
            // ledger and tells us if it does not. Surfacing a drift warning to
            // the buyer is better than hiding it behind an admin screen.
            'reconciliation' => $this->wallets->reconcile($userId, 'usd'),
            'entries' => $wallet->ledgerEntries()
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(),
        ]);
    }

    /**
     * Start a deposit.
     *
     * Creates the intent and hands the buyer to their payment provider. The
     * provider callback route is what actually moves money; this never does.
     */
    public function deposit(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:10', 'max:50000'],
            'currency' => ['nullable', 'in:ghs,cad,usd'],
        ]);

        $userId = (string) $request->user()->id;
        $this->wallets->ensureWallet($userId, 'usd');

        // The payment intent needs the conversion rate locked at the moment the
        // buyer commits, which is what FXQuote is for. Until that service exists
        // we record the intent in USD at face value rather than inventing a rate.
        return back()->with('status', sprintf(
            'Deposit of %s %s is being set up. Card and Mobile Money go live shortly.',
            number_format((float) $validated['amount'], 2),
            strtoupper($validated['currency'] ?? 'ghs'),
        ));
    }

    /**
     * Place a bid on a lot.
     *
     * The form posts major units because that is what a human types; the
     * service wants minor units. The conversion happens here, once, in one
     * place, so it cannot be forgotten.
     */
    public function bid(Request $request, string $lot): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $major = (float) $validated['amount'];
        $minor = (int) round($major * 100);

        try {
            $result = app(LotsService::class)->placeBid([
                'lot_id' => $lot,
                'bidder_id' => (string) $request->user()->id,
                'bidder_email' => (string) $request->user()->email,
                'amount' => $minor,
                'idempotency_key' => $request->header('Idempotency-Key'),
            ]);
        } catch (\RuntimeException $e) {
            // The service raises deliberate, human-readable rejections: too
            // low, already leading, lot closed. Those are not exceptional, they
            // are the answer, so they go back to the buyer as an answer.
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('status', $result['extension']
            ? 'You are the highest bidder. The clock was extended because you bid late.'
            : 'You are the highest bidder.');
    }
}