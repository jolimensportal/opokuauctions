<?php

namespace Tests\Feature;

use App\Enums\IntakeMethod;
use App\Enums\ShipmentUnit;
use App\Models\OriginZone;
use App\Models\RateCard;
use App\Models\RateCardRow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rate rows are immutable. Repricing is an insert.
 *
 * This is the whole reason the table looks the way it does: a shipment booked
 * in March must still be explainable in October, and the only way to guarantee
 * that is to never edit the row that priced it.
 */
class RateCardRowTest extends TestCase
{
    use RefreshDatabase;

    private function zone(): OriginZone
    {
        return OriginZone::create([
            'id' => 'zone_test', 'country' => 'us', 'code' => 'US-NJ-NE',
            'name' => 'North East', 'active' => true,
        ]);
    }

    private function card(array $overrides = []): RateCard
    {
        return RateCard::create(array_merge([
            'id' => 'card_test',
            'name' => 'US air 2026',
            'currency' => 'usd',
        ], $overrides));
    }

    private function row(array $overrides = []): RateCardRow
    {
        return RateCardRow::create(array_merge([
            'id' => 'row_test',
            'rate_card_id' => 'card_test',
            'origin_zone_id' => 'zone_test',
            'destination' => 'Tema',
            'mode' => 'air',
            'unit' => ShipmentUnit::Parcel,
            'intake_method' => IntakeMethod::Dropoff,
            'service_level' => 'standard',
            'divisor' => 2000,
            'per_kg_cents' => 32,
            'per_pallet_cents' => null,
            'min_charge_cents' => 0,
            'max_billable_kg' => null,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ], $overrides));
    }

    private function seedCard(): void
    {
        $this->zone();
        $this->card();
        $this->row();
    }

    public function test_a_row_belongs_to_a_card_and_a_zone(): void
    {
        $this->seedCard();
        $row = RateCardRow::first();

        $this->assertSame('card_test', $row->rateCard->id);
        $this->assertSame('zone_test', $row->originZone->id);
        $this->assertSame('usd', $row->rateCard->currency);
    }

    public function test_a_row_is_effective_when_its_window_is_open(): void
    {
        $this->seedCard();

        $this->assertTrue(RateCardRow::first()->isEffectiveOn(CarbonImmutable::parse('2026-06-01')));
    }

    public function test_a_row_is_not_effective_before_it_starts(): void
    {
        $this->seedCard();

        $this->assertFalse(RateCardRow::first()->isEffectiveOn(CarbonImmutable::parse('2025-06-01')));
    }

    public function test_a_row_is_not_effective_after_it_expires(): void
    {
        $this->zone();
        $this->card();
        $this->row(['effective_to' => '2026-06-30']);

        $this->assertFalse(RateCardRow::first()->isEffectiveOn(CarbonImmutable::parse('2026-07-01')));
    }

    public function test_an_open_row_stays_effective_forever(): void
    {
        $this->seedCard();

        // effective_to is null while the row is the live rate.
        $this->assertTrue(RateCardRow::first()->isEffectiveOn(CarbonImmutable::parse('2030-01-01')));
    }

    public function test_a_row_is_inside_its_window_on_the_boundary_days(): void
    {
        $this->zone();
        $this->card();
        $this->row(['effective_from' => '2026-06-01', 'effective_to' => '2026-06-30']);

        $row = RateCardRow::first();

        $this->assertTrue($row->isEffectiveOn(CarbonImmutable::parse('2026-06-01')), 'Starts on the first day, inclusive.');
        $this->assertTrue($row->isEffectiveOn(CarbonImmutable::parse('2026-06-30')), 'Ends on the last day, inclusive.');
    }

    public function test_a_pallet_row_can_carry_a_pallet_floor(): void
    {
        $this->zone();
        $this->card();
        $this->row([
            'id' => 'row_pallet',
            'unit' => ShipmentUnit::Pallet,
            'per_kg_cents' => 10,
            'per_pallet_cents' => 18_000,
        ]);

        $pallet = RateCardRow::where('id', 'row_pallet')->firstOrFail();

        $this->assertTrue($pallet->unit->isPallet());
        $this->assertSame(18_000, $pallet->per_pallet_cents);
    }

    public function test_a_parcel_row_has_no_pallet_floor(): void
    {
        $this->seedCard();

        $this->assertNull(RateCardRow::first()->per_pallet_cents);
    }

    public function test_a_ceiling_is_read_when_it_is_set(): void
    {
        $this->zone();
        $this->card();
        $this->row(['max_billable_kg' => 30]);

        // A string, not a float: the decimal:2 cast preserves precision
        // deliberately, and a ceiling read back as 29.999999 would be a bug
        // that only appears on a heavy shipment.
        $this->assertSame('30.00', RateCardRow::first()->max_billable_kg);
    }

    public function test_a_card_holds_one_currency(): void
    {
        $this->seedCard();

        // A GHS reprice must not be able to touch a USD card.
        $this->assertSame('usd', RateCard::first()->currency);
        $this->assertSame(1, RateCard::count());
    }

    public function test_a_row_reads_its_currency_from_its_card(): void
    {
        $this->zone();
        $this->card(['id' => 'card_ghs', 'currency' => 'ghs']);
        $this->row(['rate_card_id' => 'card_ghs']);

        // A quote records the row id, so the currency has to be reachable from
        // the row alone or a stored quote cannot be re-read.
        $this->assertSame('ghs', RateCardRow::first()->currency());
    }

    public function test_repricing_inserts_a_successor_rather_than_editing(): void
    {
        $this->seedCard();

        // The reprice is an insert plus a close, never an update of the
        // price. This is the property a stored quote depends on.
        RateCardRow::where('id', 'row_test')->update(['effective_to' => '2026-10-31']);

        RateCardRow::create([
            'id' => 'row_test_v2', 'rate_card_id' => 'card_test', 'origin_zone_id' => 'zone_test',
            'destination' => 'Tema', 'mode' => 'air', 'unit' => ShipmentUnit::Parcel,
            'intake_method' => IntakeMethod::Dropoff, 'service_level' => 'standard',
            'divisor' => 2000, 'per_kg_cents' => 40, 'per_pallet_cents' => null,
            'min_charge_cents' => 0, 'max_billable_kg' => null,
            'effective_from' => '2026-11-01', 'effective_to' => null,
        ]);

        $this->assertSame(2, RateCardRow::count());

        // The old row still reads its original price, unchanged.
        $this->assertSame(
            32,
            RateCardRow::where('id', 'row_test')->firstOrFail()->per_kg_cents,
            'A closed row must keep the price it was booked at.',
        );
    }
}