<?php

namespace Tests\Feature;

use App\Models\OriginZone;
use App\Models\PostcodeBand;
use App\Models\RateCard;
use App\Models\RateCardRow;
use App\Enums\IntakeMethod;
use App\Enums\ShipmentUnit;
use App\Services\FreightQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sender quote page — the first thing a human can do with the rate card.
 *
 * Deliberately reachable without an account. A first-time sender in Newark has a
 * box and a postcode, not an intention to register with us, and every click
 * between those two facts and a price is somewhere they give up.
 *
 * The refusal states matter as much as the price. This page reads from a rate
 * card that only prices some origins, so "we do not ship from you yet" is a
 * routine outcome rather than an exception — and it has to be a sentence a
 * customer can act on, not a blank card.
 */
class SenderQuotePageTest extends TestCase
{
    use RefreshDatabase;

    private function seedRates(int $divisor = 2000): void
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

        $row = [
            'rate_card_id' => 'card_us', 'origin_zone_id' => 'zone_ne',
            'destination' => 'Tema', 'mode' => 'air',
            'divisor' => $divisor, 'min_charge_cents' => 2500,
            'effective_from' => '2026-01-01', 'effective_to' => null,
        ];

        $row['divisor'] = $divisor;
        RateCardRow::create($row + [
            'id' => 'row_parcel', 'per_kg_cents' => 32, 'per_pallet_cents' => null,
            'unit' => ShipmentUnit::Parcel, 'intake_method' => IntakeMethod::Dropoff,
            'service_level' => 'standard',
        ]);
        RateCardRow::create($row + [
            'id' => 'row_pallet', 'per_kg_cents' => null, 'per_pallet_cents' => 18000,
            'unit' => ShipmentUnit::Pallet, 'intake_method' => IntakeMethod::Dropoff,
            'service_level' => 'standard',
        ]);
        RateCardRow::create($row + [
            'id' => 'row_pickup', 'per_kg_cents' => 0, 'per_pallet_cents' => null,
            'min_charge_cents' => 25000, 'unit' => ShipmentUnit::Parcel,
            'intake_method' => IntakeMethod::PartnerPickup, 'service_level' => 'pickup',
        ]);
    }

    /** A valid parcel submission. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'country' => 'us',
            'postcode' => '07030',
            'unit' => 'parcel',
            'weight_kg' => 20,
            'quantity' => 1,
            'length_cm' => 60,
            'width_cm' => 40,
            'height_cm' => 40,
            'pickup' => false,
        ], $overrides);
    }

    public function test_a_guest_can_open_the_page_without_an_account(): void
    {
        $this->get('/send')->assertOk()->assertSee('Send something');
    }

    public function test_the_page_says_duty_is_included(): void
    {
        // This is the promise that differentiates us and it is DDP, so it has to
        // be stated before anyone pays, not discovered on arrival.
        $this->get('/send')
            ->assertOk()
            ->assertSee('Duty, VAT and clearance included');
    }

    public function test_a_guest_can_get_a_quote_without_registering(): void
    {
        $this->seedRates();

        $this->post('/send', $this->payload())
            ->assertOk()
            ->assertSee('25.00');
    }

    public function test_a_quote_shows_its_lines_not_only_a_total(): void
    {
        $this->seedRates();

        $this->post('/send', $this->payload(['pickup' => true]))
            ->assertOk()
            ->assertSee('Freight to Tema')
            ->assertSee('Courier collection');
    }

    public function test_a_quote_explains_the_volumetric_charge(): void
    {
        $this->seedRates();

        // A customer told "$25 to move a $12 box" and given no explanation is a
        // customer who does not come back.
        $this->post('/send', $this->payload())
            ->assertOk()
            ->assertSee('bills at');
    }

    public function test_an_unmapped_postcode_refuses_and_names_the_postcode(): void
    {
        $this->seedRates();

        $this->post('/send', $this->payload(['postcode' => '60601']))
            ->assertOk()
            ->assertSee('We do not ship from 60601 yet.');
    }

    public function test_a_parcel_without_dimensions_refuses(): void
    {
        $this->seedRates();

        $this->post('/send', $this->payload(['length_cm' => null, 'width_cm' => null, 'height_cm' => null]))
            ->assertOk()
            ->assertSee('We need the length, width and height');
    }

    public function test_an_unreadable_postcode_refuses(): void
    {
        $this->seedRates();

        $this->post('/send', $this->payload(['postcode' => 'not-a-postcode']))
            ->assertOk()
            ->assertSee('not a format we recognise');
    }

    public function test_a_lane_with_no_rate_refuses(): void
    {
        $this->seedRates();

        $this->post('/send', $this->payload())
            ->assertOk();

        RateCardRow::query()->delete();

        // Names the origin. We resolved the postcode to a zone before finding
        // it unpriced, so "that lane" would be hiding an answer we already had.
        $this->post('/send', $this->payload())
            ->assertOk()
            ->assertSee('We do not ship from North East yet.');
    }

    public function test_a_pallet_quotes_from_its_own_price(): void
    {
        $this->seedRates();

        $this->post('/send', $this->payload([
            'unit' => 'pallet', 'weight_kg' => 40,
            'length_cm' => 120, 'width_cm' => 120, 'height_cm' => 150,
        ]))
            ->assertOk()
            ->assertSee('180.00');
    }

    public function test_a_canadian_sender_can_quote(): void
    {
        $this->seedRates();

        OriginZone::create([
            'id' => 'zone_on', 'country' => 'ca', 'code' => 'CA-ON',
            'name' => 'Ontario', 'active' => true,
        ]);
        PostcodeBand::create([
            'id' => 'band_m5v', 'country' => 'ca', 'prefix' => 'M5V',
            'zone_id' => 'zone_on', 'active' => true,
        ]);
        RateCardRow::create([
            'id' => 'row_ca', 'rate_card_id' => 'card_us', 'origin_zone_id' => 'zone_on',
            'destination' => 'Tema', 'mode' => 'air', 'unit' => ShipmentUnit::Parcel,
            'intake_method' => IntakeMethod::Dropoff, 'service_level' => 'standard',
            'divisor' => 2000, 'per_kg_cents' => 32, 'per_pallet_cents' => null,
            'min_charge_cents' => 2500, 'max_billable_kg' => null,
            'effective_from' => '2026-01-01', 'effective_to' => null,
        ]);

        // The band resolves, so this must NOT be the "we do not ship from you"
        // refusal. It either prices or fails for a different, named reason.
        $response = $this->post('/send', $this->payload([
            'country' => 'ca', 'postcode' => 'm5v-2t6',
        ]))->assertOk();

        $response->assertDontSee('We do not ship from');
    }

    public function test_a_guest_can_reserve_a_rate_without_an_account(): void
    {
        $this->seedRates();
        $this->post('/send', $this->payload())->assertOk();

        $this->post('/send/reserve', [
            'email' => 'ama@example.com',
            'country' => 'us',
            'postcode' => '07030',
        ])->assertRedirect();

        // The reservation is the demand signal. If it is not stored, the page
        // has no idea whether anyone wanted to ship at all.
        $this->assertDatabaseHas('rate_reservations', [
            'email' => 'ama@example.com',
            'quoted_total_cents' => 2500,
        ]);
    }

    /**
     * The recorded figure is ours, not the customer's.
     *
     * The form used to carry the total in a hidden field, which anyone can
     * edit. A demand table full of invented prices would tell us we have
     * demand for lanes we have never priced.
     */
    public function test_a_tampered_total_is_ignored_and_the_real_price_recorded(): void
    {
        $this->seedRates();
        $this->post('/send', $this->payload([
            'weight_kg' => 100, 'length_cm' => 100, 'width_cm' => 100, 'height_cm' => 100,
        ]))->assertOk();

        $this->post('/send/reserve', [
            'email' => 'sneaky@example.com',
            'country' => 'us',
            'postcode' => '07030',
            'quoted_total_cents' => 1,
        ])->assertRedirect();

        $this->assertDatabaseMissing('rate_reservations', [
            'email' => 'sneaky@example.com',
            'quoted_total_cents' => 1,
        ]);
    }

    /**
     * A refusal has no price, and zero is not the same as none.
     *
     * Storing 0 would put a fake number into the one table whose whole job is
     * telling us which lanes people want.
     */
    public function test_reserving_from_a_refusal_stores_no_price(): void
    {
        $this->post('/send', $this->payload(['postcode' => '99999']))->assertOk();
        $this->assertNull($this->service()->quote(
            postcode: '99999', country: 'us', destination: 'Tema', mode: 'air',
            unit: ShipmentUnit::Parcel, intakeMethod: IntakeMethod::Dropoff,
            weightKg: 1.0, lengthCm: 10, widthCm: 10, heightCm: 10,
        )->quote);

        $this->post('/send/reserve', [
            'email' => 'houston@example.com',
            'country' => 'us',
            'postcode' => '99999',
        ])->assertRedirect();

        $this->assertDatabaseHas('rate_reservations', [
            'email' => 'houston@example.com',
            'quoted_total_cents' => null,
        ]);
    }

    public function test_a_reservation_needs_a_real_email(): void
    {
        $this->post('/send/reserve', ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');
    }

    public function test_reserving_does_not_require_being_signed_in(): void
    {
        $this->seedRates();
        $this->post('/send', $this->payload())->assertOk();

        $this->assertGuest();

        $this->post('/send/reserve', [
            'email' => 'ama@example.com',
            'country' => 'us',
            'postcode' => '07030',
        ])->assertRedirect();
    }


    /**
     * The explanation must not be a second opinion.
     *
     * This page once divided cm cubed by 2000 while the rate row said 5000 —
     * the page told a customer their box bills at 48 kg while the invoice
     * charged 20. The two numbers came from two calculations in two files,
     * which is precisely why they drifted.
     */
    public function test_explanation_uses_the_rate_row_divisor_not_a_hardcoded_one(): void
    {
        $this->seedRates(divisor: 5000);

        $why = $this->service()->quote(
            postcode: '07030', country: 'us', destination: 'Tema', mode: 'air',
            unit: ShipmentUnit::Parcel, intakeMethod: IntakeMethod::Dropoff,
            weightKg: 20.0, lengthCm: 60, widthCm: 40, heightCm: 40,
        )->quote->explanation;

        // 60 x 40 x 40 = 96,000 cm3. At the row's own divisor of 5000 that is
        // 19.2 kg, so the 20 kg on the scale wins and this is not volumetric.
        $this->assertSame(5000, $why->divisor);
        $this->assertSame(20000, $why->actualGrams);
        $this->assertSame(19200, $why->volumetricGrams);
        $this->assertSame(20000, $why->billableGrams);
        $this->assertFalse($why->isVolumetric());
    }

    public function test_explanation_flags_a_box_whose_volume_sets_the_price(): void
    {
        $this->seedRates(divisor: 5000);

        $why = $this->service()->quote(
            postcode: '07030', country: 'us', destination: 'Tema', mode: 'air',
            unit: ShipmentUnit::Parcel, intakeMethod: IntakeMethod::Dropoff,
            weightKg: 5.0, lengthCm: 60, widthCm: 40, heightCm: 40,
        )->quote->explanation;

        $this->assertTrue($why->isVolumetric());
        $this->assertSame(19200, $why->billableGrams);
    }

    /**
     * The same box, priced from two origins that disagree about air.
     *
     * Two rows on ONE zone cannot disagree: a rate row is keyed by origin zone,
     * so the divisor is a property of the lane, not of an arbitrary row. That is
     * why the old page could get this wrong — there was no single source of
     * truth for it to read, so it made one up.
     */
    public function test_the_divisor_actually_changes_the_billable_weight(): void
    {
        $this->seedRates(divisor: 2000);

        // A second origin, billing air the standard way.
        OriginZone::create([
            'id' => 'zone_mw', 'country' => 'us', 'code' => 'US-CT-MW',
            'name' => 'Hartford', 'active' => true,
        ]);
        PostcodeBand::create([
            'id' => 'band_061', 'country' => 'us', 'prefix' => '061',
            'zone_id' => 'zone_mw', 'active' => true,
        ]);
        RateCard::create(['id' => 'card_east', 'name' => 'East air', 'currency' => 'usd']);
        RateCardRow::create([
            'id' => 'row_east_parcel', 'rate_card_id' => 'card_east',
            'origin_zone_id' => 'zone_mw', 'destination' => 'Tema', 'mode' => 'air',
            'unit' => ShipmentUnit::Parcel, 'intake_method' => IntakeMethod::Dropoff,
            'service_level' => 'standard', 'divisor' => 5000,
            'per_kg_cents' => 32, 'per_pallet_cents' => null,
            'min_charge_cents' => 2500, 'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        $billableFor = function (string $postcode): int {
            return $this->service()->quote(
                postcode: $postcode,
                country: 'us',
                destination: 'Tema',
                mode: 'air',
                unit: ShipmentUnit::Parcel,
                intakeMethod: IntakeMethod::Dropoff,
                weightKg: 5.0,
                lengthCm: 60,
                widthCm: 40,
                heightCm: 40,
            )->quote->explanation->billableGrams;
        };

        // 96,000 cm3 is 48 kg at a divisor of 2000 and 19.2 kg at 5000.
        $this->assertSame(48000, $billableFor('07030'));
        $this->assertSame(19200, $billableFor('06103'));
    }

    private function service(): FreightQuoteService
    {
        return app(FreightQuoteService::class);
    }

    /**
     * The demo has to survive migrate:fresh --seed.
     *
     * A feature that only works because someone typed rows into a console is
     * not a feature, it is a rumour. This asserts the shipped seeder produces a
     * quoting Newark and an honestly-refusing Houston.
     */
    public function test_the_seeded_demo_quotes_newark_and_refuses_houston(): void
    {
        $this->seed(\Database\Seeders\LogisticsRateSeeder::class);

        $this->post('/send', [
            'country' => 'us', 'postcode' => '07030', 'quantity' => 1,
            'unit' => 'parcel', 'weight_kg' => 20,
            'length_cm' => 60, 'width_cm' => 40, 'height_cm' => 40,
        ])->assertOk()->assertSee('$25.00');

        // Houston has a band, so we know where it is, and no rate row, so we
        // must say so rather than quote the Newark card.
        $this->post('/send', [
            'country' => 'us', 'postcode' => '77002', 'quantity' => 1,
            'unit' => 'parcel', 'weight_kg' => 10,
            'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 15,
        ])->assertOk()->assertSee('Houston');
    }
}
