<?php

namespace App\Services;

use App\Data\PaymentLink;
use App\Enums\IntakeMethod;
use App\Enums\ParcelBookingStatus;
use App\Enums\ParcelPaymentStatus;
use App\Enums\ShipmentUnit;
use App\Models\ParcelBooking;
use App\Models\ParcelPayment;
use App\Payments\Paystack\PaymentGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The booking state machine.
 *
 * QUOTED -> PAYMENT_PENDING -> BOOKED, with EXPIRED and ABANDONED as distinct
 * ways of not getting there.
 *
 * Two rules carry most of the weight:
 *
 *   1. A booking re-prices at confirmation. The quote was a promise made at a
 *      moment and rates move. Booking the stored number because it was quoted
 *      three weeks ago is how a rate card becomes a liability.
 *
 *   2. The payment is recorded before the booking is confirmed, in the same
 *      transaction. A paid booking that does not exist is the worst state this
 *      system can be in, so it is made impossible rather than unlikely.
 */
class ParcelBookingService
{
    /** How long a payment link stays payable. */
    public const LINK_TTL_HOURS = 24;

    /** How long a booking may sit with no reply before it is abandoned. */
    public const ABANDON_AFTER_HOURS = 168;

    public function __construct(
        private readonly FreightQuoteService $quotes,
        private readonly PaymentGateway $payments,
        private readonly FxService $fx,
    ) {}

    /**
     * Record a priced enquiry as a booking, and issue its payment link.
     *
     * @param  array  $quoteInput  the same validated input the quote was built from
     */
    public function open(
        array $quoteInput,
        int $quotedTotalCents,
        string $rateCardRowId,
        ?string $email = null,
        ?string $whatsappTo = null,
    ): ParcelBooking {
        $booking = ParcelBooking::create([
            'status' => ParcelBookingStatus::Quoted,
            'email' => $email,
            'whatsapp_to' => $whatsappTo,
            'country' => $quoteInput['country'],
            'postcode' => $quoteInput['postcode'],
            'unit' => $quoteInput['unit'] ?? 'parcel',
            // Grams. Nothing is re-rounded on the way back into a billable-weight
            // calculation, where a rounding difference becomes a different price.
            'weight_grams' => (int) round(((float) $quoteInput['weight_kg']) * 1000),
            'length_cm' => $quoteInput['length_cm'] ?? null,
            'width_cm' => $quoteInput['width_cm'] ?? null,
            'height_cm' => $quoteInput['height_cm'] ?? null,
            'pickup' => (bool) ($quoteInput['pickup'] ?? false),
            'quoted_total_cents' => $quotedTotalCents,
            'charge_currency' => strtoupper((string) config('opoku.payment_currency', 'USD')),
            'rate_card_row_id' => $rateCardRowId,
            'quote_input' => $quoteInput,
        ]);

        return $this->issuePaymentLink($booking);
    }

    /** Move to PAYMENT_PENDING and hand the customer a link for this exact amount. */
    public function issuePaymentLink(ParcelBooking $booking): ParcelBooking
    {
        if ($booking->status === ParcelBookingStatus::Booked) {
            return $booking; // Paid. A second link would be a second charge waiting to happen.
        }

        $payment = ParcelPayment::create([
            'booking_id' => $booking->id,
            'reference' => $booking->reference,
            'amount_cents' => $booking->quoted_total_cents,
            'status' => ParcelPaymentStatus::Pending,
        ]);

        // The merchant takes cedi, so a USD quote has to be converted before it
        // becomes a charge. The rate is locked at the moment the link is minted
        // and recorded, because a Paystack link is a fixed amount and the
        // number we were later paid has to be traceable back to a rate rather
        // than to whatever it happens to be today.
        $currency = strtoupper((string) config('opoku.payment_currency', 'USD'));
        $amount = $payment->amount_cents;
        $quote = null;

        if ($currency !== 'USD') {
            $quote = $this->fx->lock('usd', 'ghs');
            $amount = $this->fx->convertWithQuote($payment->amount_cents, $quote);
        }

        $url = $this->payments->createLink(new PaymentLink(
            amountCents: $amount,
            currency: $currency,
            reference: $payment->reference,
            email: $booking->email ?? 'sender@pending-address.invalid',
            description: 'Opoku parcel to Tema — booking '.$booking->reference,
        ));

        $booking->forceFill([
            'status' => ParcelBookingStatus::PaymentPending,
            'payment_link_url' => $url,
            'charge_cents' => $amount,
            'charge_currency' => $currency,
            'fx_rate' => $quote ? ($quote->rate_numerator.'/'.$quote->rate_denominator) : null,
            'expires_at' => Carbon::now()->addHours(self::LINK_TTL_HOURS),
        ])->save();

        return $booking;
    }

    /**
     * Re-price the booking from its stored inputs.
     *
     * Returns the live total, or null when it can no longer be priced at all —
     * an unpriceable booking must not silently keep an old number.
     */
    public function reprice(ParcelBooking $booking): ?int
    {
        $result = $this->quotes->quote(
            postcode: $booking->postcode,
            country: $booking->country,
            destination: 'Tema',
            mode: 'air',
            unit: ShipmentUnit::from($booking->unit),
            intakeMethod: IntakeMethod::Dropoff,
            weightKg: $booking->weightKg(),
            lengthCm: $booking->length_cm,
            widthCm: $booking->width_cm,
            heightCm: $booking->height_cm,
            pickup: $booking->pickup,
        );

        return $result->isAvailable() ? $result->quote->totalCents : null;
    }

    /**
     * Confirm a paid booking.
     *
     * Idempotent on the payment reference, which is the only safe key: payment
     * webhooks are retried and are guaranteed to arrive more than once, and a
     * replay must be a no-op rather than a second booking.
     *
     * @param  array|null  $payload  the raw webhook, kept for reconciliation
     * @return array{outcome: string, booking: ?ParcelBooking}
     */
    public function confirmPayment(string $reference, ?array $payload = null): array
    {
        return DB::transaction(
            function () use ($reference, $payload): array {
                $payment = ParcelPayment::where('reference', $reference)->first();

                if (! $payment) {
                    return ['outcome' => 'unknown_reference', 'booking' => null];
                }

                if ($payment->succeeded()) {
                    return ['outcome' => 'already_confirmed', 'booking' => $payment->booking];
                }

                $booking = $payment->booking;

                // Record the money first. A confirmed payment with no booking is
                // the worst state here; doing it in this order inside one
                // transaction makes it unreachable rather than rare.
                $payment->forceFill([
                    'status' => ParcelPaymentStatus::Succeeded,
                    'raw' => $payload,
                    'paid_at' => Carbon::now(),
                ])->save();

                $live = $this->reprice($booking);

                if ($live === null) {
                    $payment->forceFill(['status' => ParcelPaymentStatus::Failed])->save();

                    return ['outcome' => 'no_longer_priceable', 'booking' => $booking];
                }

                if ($live !== $booking->quoted_total_cents) {
                    $payment->forceFill(['status' => ParcelPaymentStatus::Failed])->save();

                    return ['outcome' => 'rate_moved', 'booking' => $booking];
                }

                $booking->forceFill([
                    'status' => ParcelBookingStatus::Booked,
                    'quoted_total_cents' => $live,
                    'booked_at' => Carbon::now(),
                ])->save();

                return ['outcome' => 'booked', 'booking' => $booking];
            }
        );
    }

    /** A payment link we issued timed out. Not the same as being ignored. */
    public function expire(ParcelBooking $booking): ParcelBooking
    {
        if ($booking->status !== ParcelBookingStatus::PaymentPending) {
            return $booking;
        }

        $booking->forceFill([
            'status' => ParcelBookingStatus::Expired,
        ])->save();

        return $booking;
    }

    /** Nobody ever replied. */
    public function abandon(ParcelBooking $booking): ParcelBooking
    {
        if (! $booking->isOpen()) {
            return $booking;
        }

        $booking->forceFill([
            'status' => ParcelBookingStatus::Abandoned,
            'abandoned_at' => Carbon::now(),
        ])->save();

        return $booking;
    }

    /**
     * Close out what has gone quiet.
     *
     * Two sweeps with different cut-offs, kept apart on purpose: a link that
     * timed out at 24 hours and a booking nobody answered at seven days are
     * different events, and merging them produces one abandonment number that
     * describes neither.
     */
    public function sweep(): array
    {
        $expired = 0;
        $abandoned = 0;

        ParcelBooking::query()
            ->where('status', ParcelBookingStatus::PaymentPending->value)
            ->where('expires_at', '<=', Carbon::now())
            ->each(function (ParcelBooking $booking) use (&$expired) {
                $this->expire($booking);
                $expired++;
            });

        ParcelBooking::query()
            ->whereIn('status', [
                ParcelBookingStatus::Quoted->value,
                ParcelBookingStatus::PaymentPending->value,
            ])
            ->where('created_at', '<=', Carbon::now()->subHours(self::ABANDON_AFTER_HOURS))
            ->each(function (ParcelBooking $booking) use (&$abandoned) {
                $this->abandon($booking);
                $abandoned++;
            });

        return compact('expired', 'abandoned');
    }
}
