<?php

namespace Tests\Feature;

use App\Models\OriginZone;
use App\Models\PostcodeBand;
use App\Models\RateCard;
use App\Models\RateCardRow;
use App\Enums\IntakeMethod;
use App\Enums\ShipmentUnit;
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

    private function seedRates(): void
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
            'divisor' => 2000, 'min_charge_cents' => 2500,
            'effective_from' => '2026-01-01', 'effective_to' => null,
        ];

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

        $this->post('/send', $this->payload())
            ->assertOk()
            ->assertSee('We do not have a price for that lane');
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

        $this->post('/send/reserve', [
            'email' => 'ama@example.com',
            'country' => 'us',
            'postcode' => '07030',
            'quoted_total_cents' => 2500,
        ])
            ->assertRedirect();

        // The reservation is the demand signal. If it is not stored, the page
        // has no idea whether anyone wanted to ship at all.
        $this->assertDatabaseHas('rate_reservations', [
            'email' => 'ama@example.com',
            'quoted_total_cents' => 2500,
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

        $this->assertGuest();

        $this->post('/send/reserve', [
            'email' => 'ama@example.com',
            'country' => 'us',
            'postcode' => '07030',
            'quoted_total_cents' => 2500,
        ])->assertRedirect();
    }
}