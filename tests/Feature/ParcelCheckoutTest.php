<?php

namespace Tests\Feature;

use App\Enums\IntakeMethod;
use App\Enums\ParcelBookingStatus;
use App\Enums\ParcelPaymentStatus;
use App\Enums\ShipmentUnit;
use App\Models\Lot;
use App\Models\OriginZone;
use App\Models\ParcelBooking;
use App\Models\ParcelPayment;
use App\Models\PostcodeBand;
use App\Models\RateCard;
use App\Models\RateCardRow;
use App\Models\User;
use App\Notifications\WhatsApp\FakeWhatsAppGateway;
use App\Payments\Paystack\FakePaymentGateway;
use App\Payments\Paystack\PaymentGateway;
use App\Services\FreightQuoteService;
use App\Services\ParcelBookingService;
use App\Support\CurbWeightLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The checkout: quote, link, pay, book.
 *
 * The state machine is the part worth testing hard, because every one of its
 * failure modes loses somebody money — a payment taken with no booking, a
 * booking honoured at a rate that has since moved, or a replayed webhook
 * creating a second shipment out of one payment.
 */
class ParcelCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway();
        $this->app->instance(PaymentGateway::class, $this->gateway);
        $this->app->instance(FakeWhatsAppGateway::class, $whatsapp = new FakeWhatsAppGateway());
        $this->app->instance(\App\Notifications\WhatsApp\WhatsAppGateway::class, $whatsapp);

        $this->seedRates();
    }

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
    }

    private function quoteInput(array $overrides = []): array
    {
        return array_merge([
            'country' => 'us', 'postcode' => '07030', 'unit' => 'parcel',
            'weight_kg' => 20, 'length_cm' => 60, 'width_cm' => 40, 'height_cm' => 40,
        ], $overrides);
    }

    private function open(array $overrides = [], ?string $email = null): ParcelBooking
    {
        $input = $this->quoteInput($overrides);

        $quoted = app(FreightQuoteService::class)->quote(
            postcode: $input['postcode'], country: $input['country'],
            destination: 'Tema', mode: 'air', unit: ShipmentUnit::from($input['unit']),
            intakeMethod: IntakeMethod::Dropoff, weightKg: (float) $input['weight_kg'],
            lengthCm: $input['length_cm'], widthCm: $input['width_cm'],
            heightCm: $input['height_cm'],
        );

        $this->assertTrue($quoted->isAvailable(), 'fixture rate should price');

        return app(ParcelBookingService::class)->open(
            quoteInput: $input,
            quotedTotalCents: $quoted->quote->totalCents,
            rateCardRowId: $quoted->quote->rateCardRowId,
            email: $email,
        );
    }

    /** Sign a body the way Paystack does. */
    private function signature(string $body): string
    {
        // Generic form: hash_hmac_sha512() does not exist on this PHP build.
        return hash_hmac('sha512', $body, $this->gateway->secretKey);
    }

    private function postWebhook(array $payload, ?string $sig = null)
    {
        $body = json_encode($payload);

        return $this->call(
            'POST', '/webhooks/paystack', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $sig ?? $this->signature($body)],
            $body,
        );
    }

    // ------------------------------------------------------------ opening

    public function test_opening_a_booking_issues_a_link_for_the_quoted_amount(): void
    {
        $booking = $this->open(email: 'ama@example.com');

        $this->assertSame(ParcelBookingStatus::PaymentPending, $booking->status);
        $this->assertSame(2500, $booking->quoted_total_cents);
        $this->assertNotNull($booking->payment_link_url);
        $this->assertNotNull($booking->expires_at);

        $link = $this->gateway->lastLink();
        $this->assertSame(2500, $link->amountCents);
        $this->assertSame('GHS', $link->currency);
        $this->assertSame($booking->reference, $link->reference);
    }

    public function test_a_booking_stores_enough_to_re_price_it(): void
    {
        $booking = $this->open();

        // Weight in grams, not kilograms, so nothing is re-rounded on the way
        // back into a billable-weight calculation.
        $this->assertSame(20000, $booking->weight_grams);
        $this->assertSame('07030', $booking->postcode);
        $this->assertSame(20.0, $booking->weightKg());

        $this->assertSame(2500, app(ParcelBookingService::class)->reprice($booking));
    }

    // --------------------------------------------------------- confirming

    public function test_a_confirmed_payment_books_the_shipment(): void
    {
        $booking = $this->open();

        $this->postWebhook([
            'event' => 'charge.success',
            'data' => ['reference' => $booking->reference, 'status' => 'success', 'amount' => 2500],
        ])->assertOk()->assertJson(['status' => 'booked']);

        $booking->refresh();
        $this->assertSame(ParcelBookingStatus::Booked, $booking->status);
        $this->assertNotNull($booking->booked_at);

        $payment = $booking->payments()->sole();
        $this->assertSame(ParcelPaymentStatus::Succeeded, $payment->status);
        $this->assertNotNull($payment->paid_at);
    }

    /**
     * A payment webhook is retried and is guaranteed at-least-once.
     *
     * If the replay produced a second booking, one payment would create two
     * shipments and somebody pays twice for one parcel.
     */
    public function test_a_replayed_webhook_does_not_book_twice(): void
    {
        $booking = $this->open();

        $payload = ['event' => 'charge.success', 'data' => ['reference' => $booking->reference]];

        $this->postWebhook($payload)->assertJson(['status' => 'booked']);
        $this->postWebhook($payload)->assertJson(['status' => 'already_confirmed']);
        $this->postWebhook($payload)->assertJson(['status' => 'already_confirmed']);

        $this->assertSame(1, ParcelPayment::where('reference', $booking->reference)->count());
        $this->assertSame(1, ParcelBooking::where('reference', $booking->reference)->count());
    }

    /**
     * A quote is a promise made at a moment, and rates move.
     */
    public function test_it_refuses_to_book_when_the_rate_has_moved(): void
    {
        $booking = $this->open();

        RateCardRow::where('id', 'row_parcel')->update(['min_charge_cents' => 3000]);

        $this->postWebhook([
            'event' => 'charge.success',
            'data' => ['reference' => $booking->reference],
        ])->assertOk()->assertJson(['status' => 'rate_moved']);

        $booking->refresh();
        $this->assertSame(ParcelBookingStatus::PaymentPending, $booking->status);
        // The money is recorded as not taken, rather than left ambiguous.
        $this->assertSame(ParcelPaymentStatus::Failed, $booking->payments()->sole()->status);
    }

    public function test_it_refuses_when_the_lane_stops_being_priceable(): void
    {
        $booking = $this->open();

        RateCardRow::query()->delete();

        $this->postWebhook([
            'event' => 'charge.success',
            'data' => ['reference' => $booking->reference],
        ])->assertJson(['status' => 'no_longer_priceable']);

        $booking->refresh();
        $this->assertNotSame(ParcelBookingStatus::Booked, $booking->status);
    }

    // -------------------------------------------------------- verification

    public function test_an_unsigned_webhook_is_ignored_entirely(): void
    {
        $booking = $this->open();

        $this->postWebhook(
            ['event' => 'charge.success', 'data' => ['reference' => $booking->reference]],
            'forged-signature',
        )->assertStatus(401);

        $booking->refresh();
        $this->assertSame(ParcelBookingStatus::PaymentPending, $booking->status);
        $this->assertSame(ParcelPaymentStatus::Pending, $booking->payments()->sole()->status);
    }

    public function test_a_webhook_with_no_signature_is_ignored(): void
    {
        $booking = $this->open();

        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $booking->reference]]);

        $this->call('POST', '/webhooks/paystack', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], $body)
            ->assertStatus(401);

        $this->assertNotSame(ParcelBookingStatus::Booked, $booking->fresh()->status);
    }

    public function test_an_event_we_do_not_handle_is_acknowledged_not_retried(): void
    {
        $this->open();

        $this->postWebhook(['event' => 'charge.failed', 'data' => ['reference' => 'whatever']])
            ->assertOk()
            ->assertJson(['status' => 'ignored']);
    }

    public function test_an_unknown_reference_is_reported_not_booked(): void
    {
        $this->postWebhook(['event' => 'charge.success', 'data' => ['reference' => 'nope']])
            ->assertOk()
            ->assertJson(['status' => 'unknown_reference']);
    }

    // ------------------------------------------------------------- expiry

    /**
     * EXPIRED and ABANDONED are different events and stay different.
     *
     * A link that timed out and a booking nobody answered are not the same
     * failure, and merging them produces one abandonment figure describing
     * neither.
     */
    public function test_a_timed_out_link_expires_and_a_silent_one_abandons(): void
    {
        $expired = $this->open();

        $booked = $this->open();

        $this->postWebhook([
            'event' => 'charge.success',
            'data' => ['reference' => $booked->reference],
        ])->assertJson(['status' => 'booked']);

        // Its link timed out 25 hours ago, 2 hours after issue.
        $expired->forceFill([
            'expires_at' => Carbon::now()->subHours(2),
            'created_at' => Carbon::now()->subDay(),
        ])->save();

        // Quoted 30 days ago and never replied to.
        $silent = $this->open();
        $silent->forceFill(['created_at' => Carbon::now()->subDays(30)])->save();

        $result = app(ParcelBookingService::class)->sweep();

        $this->assertSame(1, $result['expired']);
        $this->assertSame(1, $result['abandoned']);

        $this->assertSame(ParcelBookingStatus::Expired, $expired->fresh()->status);
        $this->assertSame(ParcelBookingStatus::Abandoned, $silent->fresh()->status);
        $this->assertNotNull($silent->fresh()->abandoned_at);

        // A paid booking is never swept.
        $this->assertSame(ParcelBookingStatus::Booked, $booked->fresh()->status);
    }

    public function test_a_booked_booking_is_never_given_a_second_link(): void
    {
        $booking = $this->open();

        $this->postWebhook([
            'event' => 'charge.success',
            'data' => ['reference' => $booking->reference],
        ])->assertJson(['status' => 'booked']);

        $urlBefore = $booking->fresh()->payment_link_url;

        app(ParcelBookingService::class)->issuePaymentLink($booking->fresh());

        $this->assertSame($urlBefore, $booking->fresh()->payment_link_url);
        $this->assertSame(1, $booking->payments()->count());
    }

    public function test_a_booking_id_and_reference_are_minted_without_being_asked_for(): void
    {
        $booking = $this->open();

        $this->assertStringStartsWith('bk_', $booking->id);
        $this->assertSame(16, strlen($booking->reference));
    }

    // ------------------------------------------------- end to end, via HTTP

    /**
     * The whole path a customer actually walks.
     *
     * Quote, then reserve with an email, and a booking must exist with a
     * payable link. The quote and the booking have to agree, or we are asking
     * someone to pay a number we cannot honour.
     */
    public function test_a_customer_can_quote_then_receive_a_payable_link(): void
    {
        $this->post('/send', $this->quoteInput())->assertOk();

        $this->post('/send/reserve', [
            'email' => 'ama@example.com',
            'country' => 'us',
            'postcode' => '07030',
            'unit' => 'parcel',
        ])->assertRedirect(route('send.create'));

        $booking = ParcelBooking::sole();

        $this->assertSame(ParcelBookingStatus::PaymentPending, $booking->status);
        $this->assertSame('ama@example.com', $booking->email);
        $this->assertSame(2500, $booking->quoted_total_cents);
        $this->assertNotNull($booking->payment_link_url);

        // The link the customer is handed matches the price they were shown.
        $this->assertSame(2500, $this->gateway->lastLink()->amountCents);

        // And following the redirect shows them the button.
        $this->get('/send')
            ->assertOk()
            ->assertSee($booking->payment_link_url, false)
            ->assertSee('USD 25.00');
    }

    /**
     * No rate, no link.
     *
     * Minting a payment link against a lane we cannot price produces a button
     * that takes money for something we have no figure for.
     */
    public function test_an_unpriceable_lane_reserves_without_issuing_a_link(): void
    {
        $this->post('/send', $this->quoteInput(['postcode' => '99999']))->assertOk();

        $this->post('/send/reserve', [
            'email' => 'ama@example.com',
            'country' => 'us',
            'postcode' => '99999',
        ])->assertRedirect();

        // The demand signal is still captured.
        $this->assertDatabaseHas('rate_reservations', ['email' => 'ama@example.com']);
        $this->assertSame(0, ParcelBooking::count());
    }

    public function test_the_booking_still_records_what_was_asked_for(): void
    {
        $booking = $this->open(['weight_kg' => 7.5, 'pickup' => true]);

        $this->assertSame(7500, $booking->weight_grams);
        $this->assertTrue($booking->pickup);
        $this->assertSame('07030', $booking->quote_input['postcode']);
    }
}
