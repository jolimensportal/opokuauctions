<?php

namespace Tests\Feature;

use App\Enums\IntakeMethod;
use App\Enums\ShipmentUnit;
use App\Models\OriginZone;
use App\Models\PostcodeBand;
use App\Models\RateCard;
use App\Models\RateCardRow;
use App\Services\FreightQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The WhatsApp handoff on the quote page.
 *
 * Booking does not exist yet, so this page offers two ways to continue: leave an
 * email, or open a chat. The chat is the cheaper of the two to build and the
 * more honest signal — it tells us whether anyone will pay the quoted number
 * before anyone spends a day on a booking flow.
 *
 * The message goes out prefilled with the quote, so the conversation opens with
 * facts rather than a re-description of the parcel.
 */
class SenderWhatsAppHandoffTest extends TestCase
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
        RateCardRow::create([
            'id' => 'row_parcel', 'rate_card_id' => 'card_us',
            'origin_zone_id' => 'zone_ne', 'destination' => 'Tema', 'mode' => 'air',
            'unit' => ShipmentUnit::Parcel, 'intake_method' => IntakeMethod::Dropoff,
            'service_level' => 'standard', 'divisor' => 5000,
            'per_kg_cents' => 32, 'per_pallet_cents' => null,
            'min_charge_cents' => 2500, 'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);

        // Collection is priced by its own row. Without one the whole quote is
        // refused rather than quoted with a free collection that would not
        // happen, so a test that wants collection in the message has to price
        // it.
        RateCardRow::create([
            'id' => 'row_pickup', 'rate_card_id' => 'card_us',
            'origin_zone_id' => 'zone_ne', 'destination' => 'Tema', 'mode' => 'air',
            'unit' => ShipmentUnit::Parcel, 'intake_method' => IntakeMethod::PartnerPickup,
            'service_level' => 'standard', 'divisor' => 5000,
            'per_kg_cents' => null, 'per_pallet_cents' => null,
            'min_charge_cents' => 15000, 'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
    }

    private function quote(): array
    {
        return [
            'country' => 'us', 'postcode' => '07030', 'quantity' => 1,
            'unit' => 'parcel', 'weight_kg' => 20,
            'length_cm' => 60, 'width_cm' => 40, 'height_cm' => 40,
        ];
    }

    public function test_the_handoff_carries_the_quote_it_was_shown(): void
    {
        config(['opoku.whatsapp' => '233300000000']);
        $this->seedRates();

        $html = $this->post('/send', $this->quote())
            ->assertOk()
            ->assertSee('Message us on WhatsApp')
            ->getContent();

        $this->assertStringContainsString('https://wa.me/233300000000', $html);

        // The prefilled message must state the facts the customer just saw.
        $message = $this->decodePrefill($html);
        $this->assertStringContainsString('07030', $message);
        $this->assertStringContainsString('20 kg', $message);
        $this->assertStringContainsString('60 x 40 x 40', $message);
        $this->assertStringContainsString('USD 25.00', $message);
    }

    public function test_a_volumetric_quote_says_so_in_the_message(): void
    {
        config(['opoku.whatsapp' => '233300000000']);
        $this->seedRates();

        $html = $this->post('/send', ['weight_kg' => 5] + $this->quote())
            ->assertOk()->getContent();

        // 96,000 cm3 at divisor 5000 is 19.2 kg, well over the 5 kg on the
        // scale — the same fact the page explains above the fold.
        $this->assertStringContainsString('19.2 kg by volume', $this->decodePrefill($html));
    }

    public function test_collection_is_stated_when_they_asked_for_it(): void
    {
        config(['opoku.whatsapp' => '233300000000']);
        $this->seedRates();

        $without = $this->decodePrefill(
            $this->post('/send', $this->quote())->assertOk()->getContent()
        );
        $with = $this->decodePrefill(
            $this->post('/send', ['pickup' => '1'] + $this->quote())->assertOk()->getContent()
        );

        $this->assertStringNotContainsString('I need collection', $without);
        $this->assertStringContainsString('I need collection', $with);
    }

    /**
     * No number configured means no button at all.
     *
     * A wa.me link to a placeholder does not fail quietly — it hands a real
     * customer's parcel enquiry to nobody, and the sender is left waiting on a
     * chat that will never be answered.
     */
    public function test_no_configured_number_means_no_handoff_at_all(): void
    {
        config(['opoku.whatsapp' => '']);
        $this->seedRates();

        $this->post('/send', $this->quote())
            ->assertOk()
            ->assertDontSee('wa.me')
            ->assertDontSee('Message us on WhatsApp');
    }

    public function test_a_number_with_formatting_is_normalised_for_wa_me(): void
    {
        // People paste these out of a phone as +233 (0) 30 000 0000.
        config(['opoku.whatsapp' => '+233 (0) 30 000 0000']);
        $this->seedRates();

        $this->post('/send', $this->quote())
            ->assertOk()
            ->assertSee('https://wa.me/233030000000', false);
    }

    public function test_the_handoff_opens_safely(): void
    {
        config(['opoku.whatsapp' => '233300000000']);
        $this->seedRates();

        $this->post('/send', $this->quote())
            ->assertOk()
            ->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_a_refusal_does_not_offer_a_quote_to_send(): void
    {
        config(['opoku.whatsapp' => '233300000000']);
        $this->seedRates();

        // Nothing priced, so there is no number to carry into a chat.
        $this->post('/send', ['postcode' => '99999'] + $this->quote())
            ->assertOk()
            ->assertDontSee('wa.me');
    }

    public function test_the_quote_the_message_carries_is_the_one_we_charged(): void
    {
        config(['opoku.whatsapp' => '233300000000']);
        $this->seedRates();

        $this->post('/send', $this->quote())->assertOk();

        $charged = app(FreightQuoteService::class)->quote(
            postcode: '07030', country: 'us', destination: 'Tema', mode: 'air',
            unit: ShipmentUnit::Parcel, intakeMethod: IntakeMethod::Dropoff,
            weightKg: 20.0, lengthCm: 60, widthCm: 40, heightCm: 40,
        )->quote->totalCents;

        $message = $this->decodePrefill(
            $this->post('/send', $this->quote())->assertOk()->getContent()
        );

        $this->assertStringContainsString(
            'USD ' . number_format($charged / 100, 2),
            $message,
        );
    }

    /** Pull the text= parameter back out of the wa.me link and decode it. */
    private function decodePrefill(string $html): string
    {
        $this->assertMatchesRegularExpression(
            '/https:\/\/wa\.me\/\d+\?text=([^"\']+)/',
            $html,
            'expected a prefilled wa.me link on the page',
        );

        preg_match('/https:\/\/wa\.me\/\d+\?text=([^"\']+)/', $html, $m);

        return urldecode($m[1]);
    }
}