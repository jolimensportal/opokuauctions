<?php

namespace App\Payments;

/**
 * Webhook signature verification.
 *
 * Implemented directly rather than via an SDK, because this is the entire
 * security surface of the money-in path and it is small enough to read. If this
 * class is wrong, money can be minted from nothing.
 *
 * Every comparison uses hash_equals, never `===`, to avoid leaking the
 * expected value through timing.
 */
class WebhookVerifier
{
    /**
     * Paystack signs the RAW request body with HMAC-SHA512, base64 encoded.
     *
     * The raw body matters: re-encoding a decoded JSON payload changes key
     * order and whitespace and will not verify.
     */
    public static function verifyPaystack(string $rawBody, ?string $signature, string $secret): bool
    {
        if (blank($signature) || $secret === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha512', $rawBody, $secret, true));

        return hash_equals($expected, $signature);
    }

    /**
     * Stripe sends `t=<timestamp>,v1=<signature>`.
     *
     * The signed payload is "<timestamp>.<raw body>", and the timestamp is
     * inside the tolerance window. Without that check, an old valid signature
     * could be replayed forever — which is why tolerance is enforced here and
     * not left to the caller.
     */
    public static function verifyStripe(
        string $rawBody,
        ?string $header,
        string $secret,
        int $toleranceSeconds = 300
    ): bool {
        if (blank($header) || $secret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        if (! isset($parts['t'], $parts['v1'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];

        if ($timestamp <= 0 || abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return hash_equals($expected, $parts['v1']);
    }

    /**
     * The Stripe event id, which is what the unique index dedupes on.
     */
    public static function stripeEventId(array $payload): ?string
    {
        $id = $payload['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The Paystack event id. Paystack does not send one, so the transaction
     * reference is used instead: it is unique per transaction, and the same
     * transaction cannot legitimately be paid twice.
     */
    public static function paystackEventId(array $payload): ?string
    {
        $reference = $payload['data']['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        return 'paystack:'.$reference;
    }

    /**
     * Provider amounts arrive in MAJOR units (cedis, dollars).
     * Our ledger is in minor units. This conversion happens exactly here so no
     * other part of the system has to remember which side of the decimal it is
     * looking at.
     */
    public static function toMinorUnits(int|float $major, int $exponent = 2): int
    {
        return (int) round($major * (10 ** $exponent));
    }

    /** Currencies whose minor unit is not 1/100. */
    public static function exponentFor(string $currency): int
    {
        return match (strtoupper($currency)) {
            'JPY', 'KRW', 'VND' => 0,
            'BHD', 'JOD', 'KWD', 'OMR', 'TND' => 3,
            default => 2,
        };
    }
}