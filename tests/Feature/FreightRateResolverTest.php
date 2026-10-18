<?php

namespace Tests\Feature;

use App\Enums\IntakeMethod;
use App\Enums\QuoteUnavailableReason;
use App\Enums\ShipmentUnit;
use App\Models\OriginZone;
use App\Models\PostcodeBand;
use App\Models\RateCard;
use App\Models\RateCardRow;
use App\Services\FreightRateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Turning what a sender told us into exactly one price, or a reason there is
 * none.
 *
 * The no-quote cases matter more than the happy path. A resolver that guesses
 * is worse than one that refuses, because a guessed rate is a price a customer
 * accepts and the platform then honours.
 */
class FreightRateResolverTest extends TestCase
{
    use RefreshDatabase;

    private function seedRow(array $overrides = []): RateCardRow
    {
        OriginZone::create([
            'id' => 'zone_ne', 'country' => 'us', 'code' => 'US-NJ-NE',
            'name' => 'North East', 'active' => true,
        ]);

        PostcodeBand::create([
            'id' => 'band_070', 'country' => 'us', 'prefix' => '070',
            'zone_id' => 'zone_ne', 'active' => true,
        ]);

        RateCard::create(['id' => 'card_us', 'name' => 'US air', 'currency' => 'usd']);

        return RateCardRow::create(array_merge([
            'id' => 'row_ne', 'rate_card_id' => 'card_us', 'origin_zone_id' => 'zone_ne',
            'destination' => 'Tema', 'mode' => 'air', 'unit' => ShipmentUnit::Parcel,
            'intake_method' => IntakeMethod::Dropoff, 'service_level' => 'standard',
            'divisor' => 2000, 'per_kg_cents' => 32, 'per_pallet_cents' => null,
            'min_charge_cents' => 0, 'max_billable_kg' => null,
            'effective_from' => '2026-01-01', 'effective_to' => null,
        ], $overrides));
    }

    private function resolve(
        ?string $postcode = '07030',
        string $country = 'us',
        string $destination = 'Tema',
        ?string $serviceLevel = 'standard',
    ) {
        return app(FreightRateResolver::class)->resolve(
            postcode: $postcode,
            country: $country,
            destination: $destination,
            mode: 'air',
            unit: ShipmentUnit::Parcel,
            intakeMethod: IntakeMethod::Dropoff,
            serviceLevel: $serviceLevel,
            on: CarbonImmutable::parse('2026-06-15'),
        );
    }

    public function test_a_matching_postcode_resolves_to_one_row(): void
    {
        $this->seedRow();

        $result = $this->resolve();

        $this->assertNotNull($result->row);
        $this->assertSame('row_ne', $result->row->id);
        $this->assertNull($result->reason);
        $this->assertTrue($result->isAvailable());
    }

    public function test_an_unreadable_postcode_is_refused_with_a_reason(): void
    {
        $this->seedRow();

        $result = $this->resolve(postcode: 'nonsense');

        $this->assertNull($result->row);
        $this->assertSame(QuoteUnavailableReason::UnreadablePostcode, $result->reason);
    }

    public function test_an_unmapped_postcode_names_the_postcode(): void
    {
        $this->seedRow();

        $result = $this->resolve(postcode: '60601');

        $this->assertSame(QuoteUnavailableReason::UnmappedPostcode, $result->reason);
        $this->assertStringContainsString('60601', $result->message());
    }

    public function test_an_unpriced_lane_is_refused(): void
    {
        $this->seedRow();

        $result = $this->resolve(destination: 'Takoradi');

        // No fallback to the Tema rate. Quoting a lane we have not priced is
        // how a company loses money on it.
        $this->assertSame(QuoteUnavailableReason::UnpricedLane, $result->reason);
    }

    public function test_an_expired_rate_is_refused(): void
    {
        $this->seedRow(['effective_from' => '2025-01-01', 'effective_to' => '2025-12-31']);

        $result = $this->resolve();

        // Not "unpriced lane": we did price this lane, the price is stale, and
        // the operator needs to know which of those two it is.
        $this->assertSame(QuoteUnavailableReason::ExpiredRate, $result->reason);
    }

    public function test_a_rate_that_starts_in_the_future_is_refused(): void
    {
        $this->seedRow(['effective_from' => '2027-01-01']);

        $result = $this->resolve();

        $this->assertSame(QuoteUnavailableReason::ExpiredRate, $result->reason);
    }

    public function test_an_expired_row_does_not_shadow_a_live_successor(): void
    {
        $this->seedRow(['id' => 'row_old', 'effective_to' => '2026-05-31']);

        RateCardRow::create([
            'id' => 'row_new', 'rate_card_id' => 'card_us', 'origin_zone_id' => 'zone_ne',
            'destination' => 'Tema', 'mode' => 'air', 'unit' => ShipmentUnit::Parcel,
            'intake_method' => IntakeMethod::Dropoff, 'service_level' => 'standard',
            'divisor' => 2000, 'per_kg_cents' => 40, 'per_pallet_cents' => null,
            'min_charge_cents' => 0, 'max_billable_kg' => null,
            'effective_from' => '2026-06-01', 'effective_to' => null,
        ]);

        // Resolving on 15 June must skip the closed row rather than taking the
        // first match and reporting the lane as expired.
        $result = $this->resolve();

        $this->assertSame('row_new', $result->row?->id);
    }

    public function test_the_most_specific_service_level_wins(): void
    {
        $this->seedRow(['id' => 'row_standard']);

        RateCardRow::create([
            'id' => 'row_express', 'rate_card_id' => 'card_us', 'origin_zone_id' => 'zone_ne',
            'destination' => 'Tema', 'mode' => 'air', 'unit' => ShipmentUnit::Parcel,
            'intake_method' => IntakeMethod::Dropoff, 'service_level' => 'express',
            'divisor' => 2000, 'per_kg_cents' => 90, 'per_pallet_cents' => null,
            'min_charge_cents' => 0, 'max_billable_kg' => null,
            'effective_from' => '2026-01-01', 'effective_to' => null,
        ]);

        $result = $this->resolve(serviceLevel: 'express');

        $this->assertSame('row_express', $result->row?->id);
        $this->assertSame(90, $result->row->per_kg_cents);
    }

    public function test_an_intake_method_resolves_to_its_own_rate(): void
    {
        $this->seedRow(['id' => 'row_dropoff']);

        RateCardRow::create([
            'id' => 'row_pickup', 'rate_card_id' => 'card_us', 'origin_zone_id' => 'zone_ne',
            'destination' => 'Tema', 'mode' => 'air', 'unit' => ShipmentUnit::Parcel,
            'intake_method' => IntakeMethod::PartnerPickup, 'service_level' => 'standard',
            'divisor' => 2000, 'per_kg_cents' => 60, 'per_pallet_cents' => null,
            'min_charge_cents' => 15_000, 'max_billable_kg' => null,
            'effective_from' => '2026-01-01', 'effective_to' => null,
        ]);

        $result = app(FreightRateResolver::class)->resolve(
            postcode: '07030', country: 'us', destination: 'Tema', mode: 'air',
            unit: ShipmentUnit::Parcel, intakeMethod: IntakeMethod::PartnerPickup,
            serviceLevel: 'standard', on: CarbonImmutable::parse('2026-06-15'),
        );

        // A courier bills per stop, so pickup carries a high minimum of its own.
        $this->assertSame('row_pickup', $result->row?->id);
        $this->assertSame(15_000, $result->row->min_charge_cents);
    }

    public function test_the_resolved_row_carries_its_currency(): void
    {
        $this->seedRow();

        $this->assertSame('usd', $this->resolve()->row?->currency());
    }

    public function test_an_unavailable_result_says_why(): void
    {
        $this->seedRow();

        $result = $this->resolve(postcode: 'nonsense');

        $this->assertFalse($result->isAvailable());
        $this->assertNotSame('', $result->message());
    }
}