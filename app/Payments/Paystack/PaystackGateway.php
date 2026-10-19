<?php

namespace App\Payments\Paystack;

use App\Data\PaymentLink;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Paystack: the payment rail that actually works in Ghana.
 *
 * Meta's native in-chat payments are live in India and Brazil, not Ghana, so
 * the flow is: send a Paystack payment link inside the WhatsApp thread, the
 * customer pays with MTN MoMo, Telecel Cash or AirtelTigo Money, and Paystack
 * webhooks the confirmation back. No screenshots, no manual verification.
 *
 * Verification is HMAC-SHA512 over the raw body with the secret key. It has to
 * be the raw body: parse it first and you are hashing the sender's
 * serialisation rather than what they signed.
 */
class PaystackGateway implements PaymentGateway
{
    private const API = 'https://api.paystack.co';

    public function __construct(private readonly string $secretKey) {}

    public function createLink(PaymentLink $link): string
    {
        $response = $this->client()->post(self::API.'/transaction/initialize', [
            'email' => $link->email,
            'amount' => $this->toSmallestUnit($link->amountCents, $link->currency),
            'currency' => $link->currency,
            'reference' => $link->reference,
            'description' => $link->description,
            'channels' => ['card', 'mobile_money', 'bank'],
        ]);

        if (! $response->successful()) {
            throw new PaymentVerificationFailed(
                'Paystack refused to create a payment link: '.$response->body()
            );
        }

        $url = $response->json('data.authorization_url');

        if (blank($url)) {
            throw new PaymentVerificationFailed('Paystack returned no authorization_url');
        }

        return $url;
    }

    public function verifyWebhook(string $rawBody, ?string $signature): bool
    {
        if (blank($signature) || blank($this->secretKey)) {
            return false;
        }

        // Generic form, NOT hash_hmac_sha512(). That shorthand is absent from
        // this PHP build even though sha512 itself is available, so the typed
        // call parses fine and then fatals the moment a webhook arrives.
        $expected = hash_hmac('sha512', $rawBody, $this->secretKey);

        return hash_equals($expected, $signature);
    }

    /**
     * Paystack amounts are always in the currency's smallest unit.
     *
     * Both USD and GHS subdivide by 100, so the stored integer passes through
     * unchanged either way. The currency is what has to be right, and the
     * conversion that belongs between the two was never a currency conversion —
     * it was a unit conversion, which is easy to mistake for the real thing.
     */
    private function toSmallestUnit(int $amountCents, string $currency): int
    {
        return $amountCents;
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->secretKey)
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }
}
