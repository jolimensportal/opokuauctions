<?php

namespace Tests\Feature;

use App\Models\FreightRate;
use App\Models\Lot;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What it costs to move one car from Newark to Tema.
 *
 * This is the number a buyer weighs against their bid. It is shown before they
 * commit and it is shown again when they collect, so two rules matter more than
 * the arithmetic:
 *
 *  1. **Never guess.** A car we cannot weigh, or a corridor we have not priced,
 *     gets no quote at all. A plausible-looking number invented from a default
 *     weight is worse than no number: the buyer bids on it.
 *
 *  2. **Never guess the same way twice.** Every figure comes from the rate row
 *     operations maintains, so a forwarder's price rise is corrected in one
 *     place and the quote follows.
 *
 * Money is integer cents throughout, exactly as it is everywhere else here.
 */
class QuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuoteService $quotes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quotes = app(QuoteService::class);
    }

    /**
     * The live corridor. The figures here are the seed defaults, so a failure in
     * a later test that sets its own rate can always be told apart from one
     * caused by bad seed data.
     */
    private function rate(array $overrides = []): FreightRate
    {
        return FreightRate::create(array_merge([
            'lane' => 'na-tema',
            'usd_per_kg' => 0.32,
            'min_charge_cents' => 25000,
            'insurance_cents' => 10700,
            'pickup_cents' => 15000,
            'active' => true,
        ], $overrides));
    }

    private function lot(float|int|null $weightKg = 1800): Lot
    {
        return Lot::create([
            'id' => 'lot_'.uniqid(),
            'vin' => 'VIN-'.uniqid(),
            'year' => 2018,
            'make' => 'Honda',
            'model' => 'Accord',
            'city' => 'Detroit',
            'photos' => [],
            'starting_bid' => 100000,
            'reserve_price' => 500000,
            'bid_increment' => 25000,
            'deposit_bps' => 2000,
            'curb_weight_kg' => $weightKg,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'status' => 'LIVE',
        ]);
    }

    // -------------------------------------------------------------- the line items

    public function test_a_quote_is_the_car_weight_at_the_lane_rate(): void
    {
        $this->rate();

        // 1800 kg at USD 0.32/kg is USD 576.00.
        $quote = $this->quotes->forLot($this->lot(1800), false);

        $this->assertNotNull($quote);
        $this->assertSame(1800.0, $quote->weightKg);
        $this->assertSame(57600, $quote->freightCents);
        $this->assertSame('usd', $quote->currencyCode);
        $this->assertTrue($quote->available);
    }

    public function test_a_light_car_still_pays_the_minimum_charge(): void
    {
        $this->rate();

        // 500 kg works out at USD 160.00, below the USD 250.00 floor.
        $quote = $this->quotes->forLot($this->lot(500), false);

        $this->assertSame(25000, $quote->freightCents);
    }

    public function test_the_minimum_charge_floors_the_freight_line_and_nothing_else(): void
    {
        $this->rate();

        $quote = $this->quotes->forLot($this->lot(500), true);

        // Insurance and pickup are real charges on a light car too. A floor that
        // swallowed them would quietly under-charge every small vehicle.
        $this->assertSame(25000, $quote->freightCents);
        $this->assertSame(10700, $quote->insuranceCents);
        $this->assertSame(15000, $quote->pickupCents);
        $this->assertSame(50700, $quote->totalCents);
    }

    public function test_insurance_is_flat_per_car_not_per_kilo(): void
    {
        $this->rate();

        $light = $this->quotes->forLot($this->lot(1200), false);
        $heavy = $this->quotes->forLot($this->lot(1900), false);

        $this->assertNotSame($light->freightCents, $heavy->freightCents);
        $this->assertSame($light->insuranceCents, $heavy->insuranceCents);
        $this->assertSame(10700, $light->insuranceCents);
    }

    public function test_pickup_is_charged_only_when_the_buyer_asks_for_it(): void
    {
        $this->rate();
        $lot = $this->lot(1800);

        $collect = $this->quotes->forLot($lot, false);
        $deliver = $this->quotes->forLot($lot, true);

        $this->assertSame(0, $collect->pickupCents);
        $this->assertSame(15000, $deliver->pickupCents);
        $this->assertSame(
            15000,
            $deliver->totalCents - $collect->totalCents,
            'the only difference between the two quotes is the pickup surcharge'
        );
    }

    public function test_the_total_is_the_sum_of_the_lines(): void
    {
        $this->rate();

        $quote = $this->quotes->forLot($this->lot(1800), true);

        $this->assertSame(
            $quote->freightCents + $quote->insuranceCents + $quote->pickupCents,
            $quote->totalCents
        );
        $this->assertSame(83300, $quote->totalCents);
    }

    public function test_a_weight_with_no_neat_answer_rounds_to_whole_cents(): void
    {
        $this->rate();

        // 1234 kg x 0.32 is USD 394.88. The arithmetic is done in whole cents
        // and rounded once, so a weight with no neat answer still produces an
        // integer rather than a float creeping into the money columns.
        $quote = $this->quotes->forLot($this->lot(1234), false);

        $this->assertIsInt($quote->freightCents);
        $this->assertSame(39488, $quote->freightCents);
        $this->assertSame(50188, $quote->totalCents);
    }

    // -------------------------------------------------------------- no guessing

    public function test_a_lot_with_no_known_weight_gets_no_quote(): void
    {
        $this->rate();

        $this->assertNull(
            $this->quotes->forLot($this->lot(null), false),
            'an unweighed car must produce no quote, not a quote from a default weight'
        );
    }

    public function test_a_lot_weighing_nothing_is_also_unquotable(): void
    {
        $this->rate();

        $this->assertNull($this->quotes->forLot($this->lot(0), false));
    }

    public function test_an_unpriced_corridor_gets_no_quote(): void
    {
        $this->rate(['active' => false]);

        $this->assertNull(
            $this->quotes->forLot($this->lot(1800), false),
            'a retired rate must stop quoting rather than quote at an old price'
        );
    }

    public function test_a_corridor_without_any_rate_row_gets_no_quote(): void
    {
        $this->assertNull($this->quotes->forLot($this->lot(1800), false));
    }

    // ------------------------------------------------------- rates are editable

    public function test_raising_the_rate_raises_the_quote(): void
    {
        $rate = $this->rate();

        $before = $this->quotes->forLot($this->lot(1800), false);
        $rate->update(['usd_per_kg' => 0.40]);
        $after = $this->quotes->forLot($this->lot(1800), false);

        // Operations corrects a forwarder's price rise in one row; nobody edits
        // the formula and nobody re-deploys.
        $this->assertSame(57600, $before->freightCents);
        $this->assertSame(72000, $after->freightCents);
    }

    public function test_the_seeded_corridor_prices_a_real_car(): void
    {
        $this->seed(\Database\Seeders\FreightRateSeeder::class);

        $quote = $this->quotes->forLot($this->lot(1800), true);

        $this->assertNotNull($quote);
        $this->assertSame(57600, $quote->freightCents);
        $this->assertSame(10700, $quote->insuranceCents);
        $this->assertSame(15000, $quote->pickupCents);
        $this->assertSame(83300, $quote->totalCents);
    }
}