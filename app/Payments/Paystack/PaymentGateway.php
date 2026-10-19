<?php

namespace App\Payments\Paystack;

use App\Data\PaymentLink;

/**
 * A payment provider we can charge through.
 *
 * An interface for the same reasons WhatsAppGateway is one: the checkout is
 * testable with no account, no network and no money, and swapping provider is a
 * container binding rather than a rewrite of the booking flow.
 */
interface PaymentGateway
{
    /**
     * Create a one-time payment link for an exact amount.
     *
     * A fresh link per attempt, never a reusable one: a link that has already
     * been paid must not be payable a second time.
     */
    public function createLink(PaymentLink $link): string;

    /**
     * Verify a webhook body against the provider's signature.
     *
     * Takes the RAW body. A handler that parses first and verifies afterwards
     * is verifying something the sender chose the serialisation of.
     *
     * @throws PaymentVerificationFailed
     */
    public function verifyWebhook(string $rawBody, ?string $signature): bool;
}
