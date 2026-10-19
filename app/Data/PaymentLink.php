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

    /** Paystack's smallest unit is the cedi's pesewas, and cedi has no cents. */
    public static function forCedis(int $amountCents, string $reference, string $email, string $description): self
    {
        return new self(
            amountCents: $amountCents,
            currency: 'GHS',
            reference: $reference,
            email: $email,
            description: $description,
        );
    }
}
