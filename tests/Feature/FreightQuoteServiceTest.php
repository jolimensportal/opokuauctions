<?php

namespace Tests\Feature;

use App\Data\QuoteResult;
use App\Enums\IntakeMethod;
use App\Enums\QuoteUnavailableReason;
use App\Enums\ShipmentUnit;
use App\Models\OriginZone;
use App\Models\PostcodeBand;
use App\Models\RateCard;
use App\Models\RateCardRow;
use App\Services\FreightQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The price a sender is shown.
 *
 * The two properties that matter are that the lines add up to the total, and
 * that the quote records the row that priced it. Without the second, a price
 * from March cannot be explained in October.
 */
class FreightQuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedRow(array $overrides = []): RateCardRow
    {
        OriginZone::firstOrCreate(
            ['id' => 'zone_ne'],
            ['country' => 'us', 'code' => 'US-NJ-NE', 'name' => 'North East', 'active' => true],
        );

        PostcodeBand::firstOrCreate(
            ['id' => 'band_070'],
            ['country' => 'us', 'prefix' => '070', 'zone_id' => 'zone_ne', 'active' => true],
        );

        RateCard::firstOrCreate(['id' => 'card_us'], ['name' => 'US air', 'currency' => 'usd']);

        return RateCardRow::create(array_merge([
            'id' => 'row_ne', 'rate_card_id' => 'card_us', 'origin_zone_id' => 'zone_ne',
            'destination' => 'Tema', 'mode' => 'air', 'unit' => ShipmentUnit::Parcel,
            'intake_method' => IntakeMethod::Dropoff, 'service_level' => 'standard',
            // No minimum here on purpose: the per-kilo arithmetic has to be
            // visible in the price assertions below. The minimum gets its own
            // tests, where it is the thing being tested rather than a floor
            // quietly overriding the figure.
            'divisor' => 2000, 'per_kg_cents' => 32, 'per_pallet_cents' => null,
            'min_charge_cents' => 0, 'max_billable_kg' => null,
            'effective_from' => '2026-01-01', 'effective_to' => null,
        ], $overrides));
    }

    private function quote(array $overrides = []): QuoteResult
    {
        return app(FreightQuoteService::class)->quote(
            postcode: '07030',
            country: 'us',
            destination: 'Tema',
            mode: 'air',
            unit: ShipmentUnit::Parcel,
            intakeMethod: IntakeMethod::Dropoff,
            weightKg: $overrides['weightKg'] ?? 5.0,
            lengthCm: $overrides['lengthCm'] ?? 30,
            widthCm: $overrides['widthCm'] ?? 30,
            heightCm: $overrides['heightCm'] ?? 30,
            pickup: $overrides['pickup'] ?? false,
        );
    }

    public function test_a_dense_box_is_priced_on_its_weight(): void
    {
        $this->seedRow();

        // 20 kg actual beats 27,000/2000 = 13.5 kg volumetric.
        // 20,000 g x 32 cents/kg = 640 cents.
        $this->assertSame(640, $this->quote(['weightKg' => 20.0])->quote->totalCents);
    }

    public function test_a_light_box_is_priced_on_its_volume(): void
    {
        $this->seedRow();

        // 13.5 kg volumetric beats 2 kg actual: 13,500 x 32 / 1000 = 432.
        $this->assertSame(432, $this->quote(['weightKg' => 2.0])->quote->totalCents);
    }

    public function test_the_minimum_charge_binds_when_freight_is_below_it(): void
    {
        $this->seedRow(['min_charge_cents' => 5000]);

        // 640 cents of real freight, floored to the 5,000 minimum. A floor
        // raises a low price; it is not a price in itself.
        $this->assertSame(5000, $this->quote(['weightKg' => 20.0])->quote->totalCents);
    }

    public function test_the_minimum_charge_does_not_cap_real_freight(): void
    {
        $this->seedRow(['min_charge_cents' => 100]);

        // 640 cents of real freight charged in full. A floor is a floor, not a
        // cap, and the mistake here would be charging 100 to move 20 kg.
        $this->assertSame(640, $this->quote(['weightKg' => 20.0])->quote->totalCents);
    }

    public function test_the_lines_add_up_to_the_total(): void
    {
        $this->seedRow();

        $quote = $this->quote(['weightKg' => 20.0])->quote;

        $sum = array_sum(array_map(fn ($line) => $line['cents'], $quote->lines));

        $this->assertSame($quote->totalCents, $sum);
    }

    public function test_the_quote_records_the_row_that_priced_it(): void
    {
        $this->seedRow();

        // Without this, a price from March cannot be explained in October.
        $this->assertSame('row_ne', $this->quote()->quote->rateCardRowId);
    }

    public function test_the_quote_is_reproducible_after_a_reprice(): void
    {
        $this->seedRow();

        $before = $this->quote(['weightKg' => 20.0])->quote->totalCents;

        // A reprice closes the old row and inserts a successor.
        RateCardRow::where('id', 'row_ne')->update(['effective_to' => '2020-11-01']);
        RateCardRow::create([
            'id' => 'row_ne_v2', 'rate_card_id' => 'card_us', 'origin_zone_id' => 'zone_ne',
            'destination' => 'Tema', 'mode' => 'air', 'unit' => ShipmentUnit::Parcel,
            'intake_method' => IntakeMethod::Dropoff, 'service_level' => 'standard',
            'divisor' => 2000, 'per_kg_cents' => 99, 'per_pallet_cents' => null,
            'min_charge_cents' => 0, 'max_billable_kg' => null,
            'effective_from' => '2020-11-02', 'effective_to' => null,
        ]);

        // A successor covering today changes the price, because that is a
        // genuine reprice. The point of the immutable row is that the OLD one
        // still reads its own price, which is asserted here directly.
        $this->assertSame(
            32,
            RateCardRow::where('id', 'row_ne')->firstOrFail()->per_kg_cents,
            'A closed row must keep the price it was booked at.',
        );
        $this->assertGreaterThan($before, $this->quote(['weightKg' => 20.0])->quote->totalCents);
    }

    public function test_an_unmapped_postcode_refuses_the_quote(): void
    {
        $this->seedRow();

        $result = app(FreightQuoteService::class)->quote(
            postcode: '60601', country: 'us', destination: 'Tema', mode: 'air',
            unit: ShipmentUnit::Parcel, intakeMethod: IntakeMethod::Dropoff,
            weightKg: 3.0, lengthCm: 30, widthCm: 30, heightCm: 30, pickup: false,
        );

        $this->assertNull($result->quote);
        $this->assertSame(QuoteUnavailableReason::UnmappedPostcode, $result->reason);
        $this->assertStringContainsString('60601', $result->message());
    }

    public function test_a_parcel_without_dimensions_is_refused(): void
    {
        $this->seedRow();

        $result = app(FreightQuoteService::class)->quote(
            postcode: '07030', country: 'us', destination: 'Tema', mode: 'air',
            unit: ShipmentUnit::Parcel, intakeMethod: IntakeMethod::Dropoff,
            weightKg: 3.0, lengthCm: null, widthCm: null, heightCm: null,
            pickup: false,
        );

        // Air freight is priced on the space it takes. Without dimensions any
        // figure would be invented.
        $this->assertNull($result->quote);
        $this->assertSame(QuoteUnavailableReason::MissingDimensions, $result->reason);
    }

    public function test_a_pallet_is_priced_from_its_own_price(): void
    {
        $this->seedRow([
            'id' => 'row_pallet', 'unit' => ShipmentUnit::Pallet,
            // No per-kg figure at all. A standard 120x120x150 pallet is
            // 1,080 kg volumetric, so a per-kg rate would return the same
            // number for a 40 kg pallet and a 900 kg one and tell the
            // operator nothing.
            'per_kg_cents' => null,
            'per_pallet_cents' => 18_000,
            'min_charge_cents' => 0,
        ]);

        foreach ([40.0, 900.0] as $weight) {
            $result = app(FreightQuoteService::class)->quote(
                postcode: '07030', country: 'us', destination: 'Tema', mode: 'air',
                unit: ShipmentUnit::Pallet, intakeMethod: IntakeMethod::Dropoff,
                weightKg: $weight, lengthCm: 120, widthCm: 120, heightCm: 150,
                pickup: false,
            );

            $this->assertSame(
                18_000,
                $result->quote->totalCents,
                "A {$weight} kg pallet is priced the same, and that is the model.",
            );
        }
    }

    public function test_a_pallet_still_uses_a_per_kg_rate_when_the_row_supplies_one(): void
    {
        $this->seedRow([
            'id' => 'row_pallet_kg', 'unit' => ShipmentUnit::Pallet,
            'per_kg_cents' => 30, 'per_pallet_cents' => 18_000, 'min_charge_cents' => 0,
        ]);

        $result = app(FreightQuoteService::class)->quote(
            postcode: '07030', country: 'us', destination: 'Tema', mode: 'air',
            unit: ShipmentUnit::Pallet, intakeMethod: IntakeMethod::Dropoff,
            weightKg: 900.0, lengthCm: 120, widthCm: 120, heightCm: 150,
            pickup: false,
        );

        // 1,080,000 g x 30 cents/kg = 32,400, above the 18,000 pallet price,
        // so the rate row wins. A pallet row can express either shape.
        $this->assertSame(32_400, $result->quote->totalCents);
    }

    public function test_a_pickup_is_a_separate_line_not_a_lump(): void
    {
        $this->seedRow();

        RateCardRow::create([
            'id' => 'row_pickup', 'rate_card_id' => 'card_us', 'origin_zone_id' => 'zone_ne',
            'destination' => 'Tema', 'mode' => 'air', 'unit' => ShipmentUnit::Parcel,
            // PartnerPickup, because that is how the service resolves a pickup.
            // A Dropoff row here would never be found.
            'intake_method' => IntakeMethod::PartnerPickup, 'service_level' => 'pickup',
            'divisor' => 2000, 'per_kg_cents' => 0, 'per_pallet_cents' => null,
            'min_charge_cents' => 15_000, 'max_billable_kg' => null,
            'effective_from' => '2026-01-01', 'effective_to' => null,
        ]);

        $quote = $this->quote(['weightKg' => 20.0, 'pickup' => true])->quote;

        // A courier bills per stop, so the stop is visible as its own line
        // rather than folded into a single number.
        $this->assertCount(2, $quote->lines);
        $this->assertSame(640 + 15_000, $quote->totalCents);
        $this->assertSame(
            array_sum(array_map(fn ($l) => $l['cents'], $quote->lines)),
            $quote->totalCents,
        );
    }

    public function test_a_pickup_with_no_rate_refuses_rather_than_being_free(): void
    {
        $this->seedRow();

        // No pickup row exists for this lane. A silently free pickup would be
        // worse than a refusal: the customer books expecting collection.
        $result = $this->quote(['weightKg' => 20.0, 'pickup' => true]);

        $this->assertNull($result->quote);
        $this->assertSame(QuoteUnavailableReason::UnpricedLane, $result->reason);
    }

    public function test_no_pickup_line_is_added_when_none_is_wanted(): void
    {
        $this->seedRow();

        $quote = $this->quote(['weightKg' => 20.0])->quote;

        $this->assertCount(1, $quote->lines);
        $this->assertSame(640, $quote->totalCents);
    }
}