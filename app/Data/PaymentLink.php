<?php

namespace App\Data;

/**
 * What we are asking someone to pay.
 *
 * Deliberately not tied to Paystack's field names. The amount is integer cents
 * because that is what the booking stores and what has to survive the round
 * trip; converting to whatever a provider calls it is the gateway's job.
 */
final readonly class PaymentLink
{
    public function __construct(
        public int $amountCents,
        public string $currency,
        public string $reference,
        public string $email,
        public string $description,
        /** Where the customer is sent back to. Null opens the hosted page only. */
        public ?string $callbackUrl = null,
    ) {}

    /**
     * What the customer quoted in: USD.
     *
     * The quote page prices in USD, the sender is in the USA or Canada, and the
     * figure stored on the booking is USD cents. So the charge has to be USD
     * too, and the cents pass straight through — Paystack's USD smallest unit
     * is the cent.
     *
     * This was briefly charging GHS 25 for a USD 25.00 quote, which is about
     * $1.67: the checkout would have quietly lost ninety per cent of every
     * booking. The currency is not a presentation detail here.
     */
    public static function forDollars(
        int $amountCents,
        string $reference,
        string $email,
        string $description,
        string $currency = 'USD',
    ): self {
        return new self(
            amountCents: $amountCents,
            currency: strtoupper($currency),
            reference: $reference,
            email: $email,
            description: $description,
        );
    }

    /**
     * Charging in cedi.
     *
     * Only correct when a party inside Ghana is the one paying, and only when
     * the amount has been converted at a real rate — the figure on the booking
     * is USD and is not a GHS figure by any reading.
     */
    public static function forCedis(int $amountPesawas, string $reference, string $email, string $description): self
    {
        return new self(
            amountCents: $amountPesawas,
            currency: 'GHS',
            reference: $reference,
            email: $email,
            description: $description,
        );
    }
}
