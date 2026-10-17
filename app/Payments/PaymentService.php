<?php

namespace App\Payments;

use App\Models\PaymentIntent;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Taking money in.
 *
 * The only operation in this platform that creates value. Everything else
 * moves it. So the rule is absolute:
 *
 *   **No verified, matching provider callback, no deposit.**
 *
 * Four gates stand between a webhook and a balance, and all four must pass:
 *
 *  1. The signature verifies against the raw body.
 *  2. The event id has not been seen before (a unique index, not a check).
 *  3. A payment we initiated exists for that provider reference.
 *  4. The amount and currency the provider reports match what we asked for.
 *
 * A webhook that fails any gate is recorded and rejected. It never partially
 * applies, and it never silently half-succeeds.
 */
class PaymentService
{
    public function __construct(private readonly WalletService $wallets)
    {
    }

    /**
     * Record the intent to take money, before the provider is involved.
     *
     * Having a row here is what makes gate 3 possible: a webhook we never
     * asked for has nothing to match, and so cannot create a balance.
     */
    public function createIntent(
        string $userId,
        string $provider,
        int $amount,
        string $currencyCode
    ): PaymentIntent {
        if (! in_array($provider, ['paystack', 'stripe'], true)) {
            throw new RuntimeException("Unsupported payment provider: {$provider}");
        }

        if (! is_int($amount) || $amount <= 0) {
            throw new RuntimeException('Deposit amount must be a positive integer');
        }

        $wallet = $this->wallets->ensureWallet($userId, $currencyCode);

        return PaymentIntent::create([
            'id' => 'pi_'.Str::random(20),
            'user_id' => $userId,
            'wallet_id' => $wallet->id,
            'provider' => $provider,
            'amount' => $amount,
            'currency_code' => $currencyCode,
            // We generate the reference and send it to the provider, so the
            // callback echoes back something we already hold. Matching on the
            // provider's own id instead could never work, because we do not
            // have it until the callback arrives.
            'provider_reference' => 'opk_'.Str::lower(Str::random(24)),
            'status' => 'initiated',
        ]);
    }

    /**
     * Handle a Paystack `charge.success` callback.
     *
     * @return array{status: string, reason?: string}
     */
    public function handlePaystack(array $payload, bool $signatureValid): array
    {
        $event = (string) ($payload['event'] ?? '');

        if ($event !== 'charge.success') {
            // Not an inbound payment. Logged and ignored, never an error.
            return ['status' => 'ignored', 'reason' => "event {$event}"];
        }

        if (! $signatureValid) {
            Log::warning('Paystack webhook rejected: bad signature');

            return ['status' => 'rejected', 'reason' => 'invalid signature'];
        }

        $reference = $payload['data']['reference'] ?? null;
        $amountMajor = $payload['data']['amount'] ?? null;
        $currency = strtoupper((string) ($payload['data']['currency'] ?? ''));

        if (! is_string($reference) || $reference === '' || $amountMajor === null) {
            return ['status' => 'rejected', 'reason' => 'malformed payload'];
        }

        $eventId = WebhookVerifier::paystackEventId($payload);

        return $this->settle(
            provider: 'paystack',
            providerReference: $reference,
            matchedOn: 'reference',
            eventId: $eventId,
            receivedAmount: (int) $amountMajor,
            receivedCurrency: $currency,
            rawPayload: $payload,
        );
    }

    /**
     * Handle a Stripe `payment_intent.succeeded` callback.
     */
    public function handleStripe(array $payload, bool $signatureValid): array
    {
        $type = $payload['type'] ?? null;

        if ($type !== 'payment_intent.succeeded') {
            return ['status' => 'ignored', 'reason' => "event type {$type}"];
        }

        if (! $signatureValid) {
            Log::warning('Stripe webhook rejected: bad signature');

            return ['status' => 'rejected', 'reason' => 'invalid signature'];
        }

        $object = $payload['data']['object'] ?? [];
        // client_reference_id is what we set at initiation; the provider's own
        // payment-intent id is useless to us because we have never seen it.
        $reference = $object['client_reference_id'] ?? null;
        $amountMinor = $object['amount'] ?? null;
        $currency = strtoupper((string) ($object['currency'] ?? ''));

        if (! is_string($reference) || $reference === '' || $amountMinor === null) {
            return ['status' => 'rejected', 'reason' => 'malformed payload'];
        }

        // Stripe already reports minor units, so no conversion here. That
        // asymmetry with Paystack is exactly why the conversion lives in one
        // place per provider rather than being spread around.
        return $this->settle(
            provider: 'stripe',
            providerReference: $reference,
            matchedOn: 'client_reference_id',
            eventId: WebhookVerifier::stripeEventId($payload),
            receivedAmount: (int) $amountMinor,
            receivedCurrency: $currency,
            rawPayload: $payload,
        );
    }

    /**
     * The four gates, in order.
     */
    private function settle(
        string $provider,
        string $providerReference,
        string $matchedOn,
        ?string $eventId,
        int|string $receivedAmount,
        string $receivedCurrency,
        array $rawPayload
    ): array {
        return DB::transaction(function () use (
            $provider,
            $providerReference,
            $matchedOn,
            $eventId,
            $receivedAmount,
            $receivedCurrency,
            $rawPayload
        ) {
            $intent = PaymentIntent::where('provider', $provider)
                ->where(function ($query) use ($providerReference, $eventId) {
                    $query->where('provider_reference', $providerReference);
                    if ($eventId) {
                        $query->orWhere('provider_event_id', $eventId);
                    }
                })
                ->lockForUpdate()
                ->first();

            // Gate 3: we never asked for this.
            if (! $intent) {
                Log::warning('Payment callback with no matching intent', [
                    'provider' => $provider,
                    'matched_on' => $matchedOn,
                    'reference' => $providerReference,
                ]);

                return ['status' => 'rejected', 'reason' => 'no matching payment intent'];
            }

            // Gate 2: already seen. The unique index is the real guard; this
            // makes the common case a cheap read instead of a constraint hit.
            if ($intent->status === 'paid') {
                return ['status' => 'duplicate', 'reason' => 'already settled'];
            }

            if ($eventId) {
                $seen = PaymentIntent::where('provider_event_id', $eventId)
                    ->where('id', '!=', $intent->id)
                    ->exists();

                if ($seen) {
                    return ['status' => 'duplicate', 'reason' => 'event id already processed'];
                }
            }

            // Normalise what the provider reported into minor units.
            $receivedMinor = is_int($receivedAmount) && strtolower((string) $provider) === 'stripe'
                ? $receivedAmount
                : WebhookVerifier::toMinorUnits(
                    (int) $receivedAmount,
                    WebhookVerifier::exponentFor($receivedCurrency)
                );

            // Gate 4: the amount must be exactly what we asked for.
            $expectedCurrency = strtoupper($intent->currency_code);

            if ($receivedMinor !== (int) $intent->amount) {
                $intent->update([
                    'received_amount' => $receivedMinor,
                    'received_currency' => $receivedCurrency,
                ]);

                Log::error('Payment amount mismatch', [
                    'intent' => $intent->id,
                    'expected' => $intent->amount,
                    'received' => $receivedMinor,
                ]);

                return ['status' => 'rejected', 'reason' => 'amount mismatch'];
            }

            if ($receivedCurrency !== $expectedCurrency) {
                $intent->update([
                    'received_amount' => $receivedMinor,
                    'received_currency' => $receivedCurrency,
                ]);

                return ['status' => 'rejected', 'reason' => 'currency mismatch'];
            }

            // All four gates passed. This is the only line in the codebase that
            // creates money.
            $this->wallets->deposit(
                $intent->user_id,
                (int) $intent->amount,
                $intent->currency_code,
                ['type' => 'payment', 'id' => $intent->id],
            );

            $intent->update([
                'status' => 'paid',
                'provider_event_id' => $eventId,
                'received_amount' => $receivedMinor,
                'received_currency' => $receivedCurrency,
                'raw_payload' => $rawPayload,
                'paid_at' => now(),
            ]);

            return ['status' => 'paid', 'intent_id' => $intent->id];
        });
    }
}