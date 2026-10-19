<?php

namespace App\Payments\Paystack;

use App\Data\PaymentLink;
/**
 * Records instead of charging.
 *
 * The checkout state machine is the part worth testing hard, and none of it is
 * reachable without either a Paystack account or this.
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var array<int, PaymentLink> */
    public array $links = [];

    public string $secretKey = 'test-secret';

    public bool $shouldFail = false;

    /** When false, every webhook is rejected — the default-safe case. */
    public bool $acceptWebhooks = true;

    public function createLink(PaymentLink $link): string
    {
        if ($this->shouldFail) {
            throw new PaymentVerificationFailed('Simulated provider failure');
        }

        $this->links[] = $link;

        return 'https://checkout.paystack.com/test/'.$link->reference;
    }

    public function verifyWebhook(string $rawBody, ?string $signature): bool
    {
        if (! $this->acceptWebhooks) {
            return false;
        }

        // Generic form, for the same reason as the real gateway.
        $expected = hash_hmac('sha512', $rawBody, $this->secretKey);

        return hash_equals($expected, (string) $signature);
    }

    public function lastLink(): ?PaymentLink
    {
        return $this->links === [] ? null : end($this->links);
    }
}
