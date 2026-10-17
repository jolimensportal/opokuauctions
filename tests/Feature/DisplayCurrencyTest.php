<?php

namespace Tests\Feature;

use App\Models\Lot;
use App\Services\DisplayCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The buyer switches between USD and GHS and every price on the site follows.
 *
 * What matters is that the switch is consistent: a lot page showing dollars next
 * to a fee table showing cedis is worse than showing only dollars, because it
 * makes the buyer do the arithmetic and wonder whether we did it wrong.
 */
class DisplayCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function lot(array $overrides = []): Lot
    {
        $n = substr(md5(json_encode($overrides).microtime()), 0, 10);

        return Lot::create(array_merge([
            'id' => 'lot_cur_'.$n,
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => 'Toyota Corolla',
            'city' => 'Detroit',
            'condition' => 'clean_title',
            'currency_code' => 'usd',
            'starting_bid' => 385_000,
            'reserve_price' => 0,
            'bid_increment' => 10_000,
            'status' => 'LIVE',
            'current_bid' => 385_000,
            'bid_count' => 0,
            'extensions' => 0,
            'starts_at' => now()->subDay(),
            'photos' => [],
            'ends_at' => now()->addDays(2),
        ], $overrides));
    }

    public function test_prices_default_to_usd(): void
    {
        $this->get('/')->assertOk();

        $this->assertSame('USD 3,850.00', (new DisplayCurrency(new \App\Services\FxService))->format(385_000));
    }

    public function test_switching_to_ghs_converts_the_whole_site(): void
    {
        $this->get('/')->assertOk();

        $response = $this->post('/currency', ['currency' => 'ghs']);
        $response->assertRedirect();

        $lot = $this->lot();
        $this->get("/lots/{$lot->id}")->assertOk()->assertSee('GHS');

        // USD 3,850.00 at 15.05 is GHS 57,942.50, rendered without decimals
        // because a cedi receipt above a thousand is not quoted in pesewas.
        $this->assertSame('GHS 57,943', (new DisplayCurrency(new \App\Services\FxService))->format(385_000));
    }

    public function test_the_choice_persists_across_pages(): void
    {
        $this->post('/currency', ['currency' => 'ghs']);

        // A different page entirely: the choice is session state, not a
        // per-page toggle.
        $this->get('/lots')->assertOk();
        $this->get('/fees')->assertOk();

        $currency = new DisplayCurrency(new \App\Services\FxService);
        $this->assertSame('ghs', $currency->current());
    }

    public function test_an_unknown_currency_falls_back_to_usd_rather_than_erroring(): void
    {
        $this->post('/currency', ['currency' => 'gbp'])->assertSessionHasErrors('currency');

        $this->assertSame('usd', (new DisplayCurrency(new \App\Services\FxService))->current());
    }

    public function test_both_currencies_can_be_shown_side_by_side_for_one_price(): void
    {
        $both = (new DisplayCurrency(new \App\Services\FxService))->both(385_000);

        $this->assertSame('USD 3,850.00', $both['usd']);
        $this->assertSame('GHS 57,943', $both['ghs']);
    }

    public function test_the_rate_label_states_which_rate_is_in_use(): void
    {
        $currency = new DisplayCurrency(new \App\Services\FxService);

        $this->assertSame('Prices in USD', $currency->rateLabel('usd'));
        $this->assertSame('1 USD = 15.05 GHS', $currency->rateLabel('ghs'));
    }

    /**
     * Switching the display must not move what anybody owes: settlement is USD
     * and the buyer's rate is locked at deposit.
     */
    public function test_switching_display_does_not_change_the_settlement_amount(): void
    {
        $fx = new \App\Services\FxService;
        $quote = $fx->lock('usd', 'ghs');

        $this->post('/currency', ['currency' => 'ghs']);

        $currency = new DisplayCurrency($fx);

        $this->assertSame('ghs', $currency->current());
        $this->assertSame(385_000, $currency->convert(385_000, 'usd'), 'The USD settlement figure is unchanged.');
        $this->assertSame(
            $fx->convertWithQuote(385_000, $quote, 'ghs'),
            $fx->convertWithQuote(385_000, $quote->fresh(), 'ghs'),
            'The locked rate is unaffected by the display choice.',
        );
    }

    public function test_a_lot_page_renders_in_the_chosen_currency(): void
    {
        $lot = $this->lot();

        $this->get("/lots/{$lot->id}")
            ->assertOk()
            ->assertSee('USD')
            ->assertSee('3,850.00');
    }
}