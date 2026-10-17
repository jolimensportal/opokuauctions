<?php

namespace Tests\Feature;

use App\Models\FxQuote;
use App\Models\Lot;
use App\Services\FxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * FX is the one place where a rounding error becomes a customer dispute.
 *
 * The buyer is quoted GHS, charged USD, and told the rate is locked. These
 * assert all three of those claims hold, and that the lock actually survives a
 * rate change between quoting and paying.
 */
class FxServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fx(): FxService
    {
        return new FxService();
    }

    private function lot(array $overrides = []): Lot
    {
        $n = substr(md5(json_encode($overrides).microtime()), 0, 10);

        return Lot::create(array_merge([
            'id' => 'lot_fx_'.$n,
            'vin' => 'FXVIN'.$n,
            'year' => 2020,
            'make' => 'Toyota',
            'model' => 'Corolla',
            'city' => 'Detroit',
            'condition' => 'clean_title',
            'currency_code' => 'usd',
            'starting_bid' => 100_000,
            'reserve_price' => 0,
            'bid_increment' => 5_000,
            'status' => 'LIVE',
            'current_bid' => 100_000,
            'bid_count' => 0,
            'extensions' => 0,
            'starts_at' => now()->subDay(),
            'photos' => [],
            'ends_at' => now()->addDay(),
        ], $overrides));
    }

    public function test_it_converts_usd_to_ghs_at_the_configured_rate(): void
    {
        // USD 1,000.00 at 15.05 is GHS 15,050.00.
        $this->assertSame(1_505_000, $this->fx()->convert(100_000, 'usd', 'ghs'));
    }

    public function test_conversion_is_exact_integer_arithmetic_not_float(): void
    {
        // A float would return 1504999.9999999998 here and then round.
        $fx = $this->fx();

        for ($minor = 1; $minor <= 500; $minor++) {
            $expected = (int) round($minor * 1505 / 100);
            $this->assertSame($expected, $fx->convert($minor, 'usd', 'ghs'));
        }
    }

    public function test_converting_to_the_same_currency_is_a_no_op(): void
    {
        $this->assertSame(123_456, $this->fx()->convert(123_456, 'usd', 'usd'));
    }

    public function test_an_unconfigured_pair_is_refused_rather_than_defaulted(): void
    {
        // A silent zero or one would quote a buyer a confidently wrong price.
        $this->expectException(RuntimeException::class);

        $this->fx()->convert(100_000, 'usd', 'gbp');
    }

    public function test_locking_a_rate_persists_it(): void
    {
        $quote = $this->fx()->lock('usd', 'ghs');

        $this->assertInstanceOf(FxQuote::class, $quote);
        $this->assertSame('usd', $quote->base_currency);
        $this->assertSame('ghs', $quote->quote_currency);
        $this->assertSame(150500, $quote->rate_numerator);
        $this->assertSame(10000, $quote->rate_denominator);
        $this->assertSame('15.0500', $this->fx()->formatRate($quote));
    }

    /** The whole point of locking: a later rate change must not move the price. */
    public function test_a_locked_quote_survives_a_change_to_the_configured_rate(): void
    {
        config(['fx.rates' => ['usd.ghs' => 15.05, 'ghs.usd' => 0.0664, 'usd.cad' => 1.36, 'cad.usd' => 0.7352]]);
        $quote = $this->fx()->lock('usd', 'ghs');

        // The market moves hard after the buyer was quoted.
        config(['fx.rates' => ['usd.ghs' => 22.00, 'ghs.usd' => 0.0455, 'usd.cad' => 1.36, 'cad.usd' => 0.7352]]);

        $locked = $this->fx()->convertWithQuote(100_000, $quote->fresh(), 'ghs');

        $this->assertSame(1_505_000, $locked, 'A locked quote must not follow the live rate.');
        $this->assertSame(2_200_000, $this->fx()->convert(100_000, 'usd', 'ghs'));
    }

    public function test_a_locked_quote_converts_back_into_its_base_unchanged(): void
    {
        $quote = $this->fx()->lock('usd', 'ghs');

        $this->assertSame(100_000, $this->fx()->convertWithQuote(100_000, $quote, 'usd'));
    }

    public function test_a_quote_refuses_to_convert_into_a_currency_it_does_not_cover(): void
    {
        $quote = $this->fx()->lock('usd', 'ghs');

        $this->expectException(RuntimeException::class);

        $this->fx()->convertWithQuote(100_000, $quote, 'cad');
    }

    public function test_a_quote_knows_when_it_expired(): void
    {
        $expired = FxQuote::create([
            'base_currency' => 'usd', 'quote_currency' => 'ghs',
            'base_minor' => 2, 'quote_minor' => 2,
            'rate_numerator' => 150500, 'rate_denominator' => 10000,
            'locked_at' => now()->subHours(3), 'expires_at' => now()->subHour(),
        ]);

        $fresh = FxQuote::create([
            'base_currency' => 'usd', 'quote_currency' => 'ghs',
            'base_minor' => 2, 'quote_minor' => 2,
            'rate_numerator' => 150500, 'rate_denominator' => 10000,
            'locked_at' => now(), 'expires_at' => now()->addHour(),
        ]);

        $this->assertTrue($expired->isExpired());
        $this->assertFalse($fresh->isExpired());
    }

    /** The two numbers on a price card must come from one conversion. */
    public function test_display_conversion_returns_usd_and_ghs_together(): void
    {
        $display = $this->fx()->convertForDisplay(100_000);

        $this->assertSame(100_000, $display['usd']);
        $this->assertSame(1_505_000, $display['ghs']);
    }

    public function test_a_lot_can_hold_the_rate_it_was_quoted_at(): void
    {
        $quote = $this->fx()->lock('usd', 'ghs');
        $lot = $this->lot(['fx_quote_id' => $quote->id, 'balance_due_usd' => 250_000]);

        $this->assertSame($quote->id, $lot->fresh()->fxQuote->id);
        $this->assertSame(250_000, $lot->fresh()->balance_due_usd);
    }

    /**
     * Shipment::lotsAwaitingPayment() read lot.balance_due_usd before the column
     * existed, so the payment gate threw on every call.
     */
    public function test_lots_awaiting_payment_can_now_be_queried(): void
    {
        $shipment = \App\Models\Shipment::create([
            'id' => 'shp_fx_test', 'status' => 'SAILING',
            'seaport_origin' => 'Newark', 'seaport_destination' => 'Tema',
        ]);

        $this->assertSame(0, $shipment->lotsAwaitingPayment()->count());
    }
}