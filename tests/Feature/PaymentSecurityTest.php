<?php

namespace Tests\Feature;

use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentService;
use App\Payments\WebhookVerifier;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The money-in path.
 *
 * These tests assert the REFUSALS first, deliberately. A payments test that
 * only checks the happy path passes while the provider signature check is
 * completely absent, and that is the bug that mints money from nothing.
 */
class PaymentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private PaymentService $payments;

    private WalletService $wallets;

    private const PAYSTACK_SECRET = 'sk_test_secret_key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->payments = app(PaymentService::class);
        $this->wallets = app(WalletService::class);
    }

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'buyer']);
    }

    /**
     * A correctly signed Paystack charge.success body.
     *
     * `$reference` is the value we generated on the intent and sent to the
     * provider, which Paystack echoes back as data.reference.
     */
    private function paystackBody(array $overrides = [], ?string $reference = null): string
    {
        $payload = array_replace_recursive([
            'event' => 'charge.success',
            'data' => [
                'reference' => $reference ?? 'unused',
                'amount' => 500, // Paystack reports MAJOR units: GHS 500.00 = 50000 minor
                'currency' => 'GHS',
                'status' => 'success',
            ],
        ], $overrides);

        return json_encode($payload);
    }

    /** Create an intent and return it, so the test can echo its reference. */
    private function intent(string $userId, string $provider = 'paystack', int $amount = 50000, string $currency = 'ghs')
    {
        $this->wallets->ensureWallet($userId, $currency);

        return $this->payments->createIntent($userId, $provider, $amount, $currency);
    }

    private function signPaystack(string $body): string
    {
        return base64_encode(hash_hmac('sha512', $body, self::PAYSTACK_SECRET, true));
    }

    // --------------------------------------------------------- signature

    public function test_a_valid_paystack_signature_verifies(): void
    {
        $body = $this->paystackBody();

        $this->assertTrue(
            WebhookVerifier::verifyPaystack($body, $this->signPaystack($body), self::PAYSTACK_SECRET)
        );
    }

    public function test_a_tampered_paystack_body_fails_verification(): void
    {
        $body = $this->paystackBody();
        $signature = $this->signPaystack($body);

        // Attacker raises the amount after the provider signed. Tamper the
        // actual bytes present in the body, whatever they currently are.
        $tampered = preg_replace('/"amount":\d+/', '"amount":999999', $body, 1);

        $this->assertNotSame($body, $tampered, 'the tamper must actually change the body');

        $this->assertFalse(
            WebhookVerifier::verifyPaystack($tampered, $signature, self::PAYSTACK_SECRET),
            'a modified body must never verify against the original signature'
        );
    }

    public function test_a_wrong_secret_fails_verification(): void
    {
        $body = $this->paystackBody();
        $signature = $this->signPaystack($body);

        $this->assertFalse(
            WebhookVerifier::verifyPaystack($body, $signature, 'sk_wrong_secret')
        );
    }

    public function test_an_empty_signature_fails_verification(): void
    {
        $body = $this->paystackBody();

        $this->assertFalse(WebhookVerifier::verifyPaystack($body, '', self::PAYSTACK_SECRET));
        $this->assertFalse(WebhookVerifier::verifyPaystack($body, null, self::PAYSTACK_SECRET));
    }

    public function test_a_stale_stripe_signature_is_rejected(): void
    {
        $body = json_encode(['id' => 'evt_1', 'type' => 'payment_intent.succeeded']);
        $secret = 'whsec_test';
        $old = time() - 3600;

        $signature = hash_hmac('sha256', $old.'.'.$body, $secret);
        $header = "t={$old},v1={$signature}";

        $this->assertFalse(
            WebhookVerifier::verifyStripe($body, $header, $secret),
            'an old but valid signature must not be replayable forever'
        );
    }

    public function test_a_current_stripe_signature_verifies(): void
    {
        $body = json_encode(['id' => 'evt_1', 'type' => 'payment_intent.succeeded']);
        $secret = 'whsec_test';
        $now = time();

        $signature = hash_hmac('sha256', $now.'.'.$body, $secret);

        $this->assertTrue(WebhookVerifier::verifyStripe($body, "t={$now},v1={$signature}", $secret));
    }

    // ------------------------------------------------------- gate 1: signature

    public function test_an_unsigned_callback_creates_no_money(): void
    {
        $buyer = $this->buyer();
        $intent = $this->intent($buyer->id);

        $result = $this->payments->handlePaystack(
            json_decode($this->paystackBody([], $intent->provider_reference), true),
            signatureValid: false
        );

        $this->assertSame('rejected', $result['status']);
        $this->assertSame(
            0,
            (int) Wallet::where('user_id', $buyer->id)->value('balance_available'),
            'an unsigned callback must not create money'
        );
    }

    // ------------------------------------------------ gate 3: we asked for it

    public function test_a_callback_we_never_asked_for_creates_no_money(): void
    {
        $buyer = $this->buyer();
        $this->wallets->ensureWallet($buyer->id, 'ghs');

        // Correctly signed, correct amount — but no intent exists.
        $body = $this->paystackBody([], 'opk_we_never_issued_this');
        $payload = json_decode($body, true);

        $result = $this->payments->handlePaystack($payload, signatureValid: true);

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('no matching payment intent', $result['reason']);
        $this->assertSame(0, (int) Wallet::where('user_id', $buyer->id)->value('balance_available'));
    }

    // --------------------------------------------------- gate 4: amount match

    public function test_an_amount_mismatch_creates_no_money(): void
    {
        $buyer = $this->buyer();
        $intent = $this->intent($buyer->id);

        // Provider says GHS 900.00 when we asked for GHS 500.00.
        $body = $this->paystackBody(['data' => ['amount' => 900]], $intent->provider_reference);

        $result = $this->payments->handlePaystack(json_decode($body, true), signatureValid: true);

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('amount mismatch', $result['reason']);
        $this->assertSame(0, (int) Wallet::where('user_id', $buyer->id)->value('balance_available'));

        // The discrepancy is kept for audit rather than discarded.
        $intent = PaymentIntent::first();
        $this->assertSame(90000, $intent->received_amount);
    }

    public function test_a_currency_mismatch_creates_no_money(): void
    {
        // Stripe reports minor units, so the amount can be held identical while
        // only the currency differs. Doing this through Paystack would conflate
        // the currency gate with the major-to-minor conversion.
        $buyer = $this->buyer();
        $intent = $this->intent($buyer->id, 'stripe', 50000, 'ghs');

        $payload = [
            'id' => 'evt_ccy',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_1',
                'client_reference_id' => $intent->provider_reference,
                'amount' => 50000, // identical minor-unit amount
                'currency' => 'cad',  // wrong currency
            ]],
        ];

        $result = $this->payments->handleStripe($payload, signatureValid: true);

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('currency mismatch', $result['reason']);
        $this->assertSame(0, (int) Wallet::where('user_id', $buyer->id)->value('balance_available'));
    }

    // ----------------------------------------------------- the happy path

    public function test_a_verified_matching_callback_credits_exactly_once(): void
    {
        $buyer = $this->buyer();
        $intent = $this->intent($buyer->id);

        $result = $this->payments->handlePaystack(
            json_decode($this->paystackBody([], $intent->provider_reference), true),
            signatureValid: true
        );

        $this->assertSame('paid', $result['status']);
        $this->assertSame(50000, (int) Wallet::where('user_id', $buyer->id)->value('balance_available'));

        // And it landed in the ledger, not just the cached balance.
        $check = $this->wallets->reconcile($buyer->id, 'ghs');
        $this->assertTrue($check['ok']);
        $this->assertSame(50000, $check['ledger']['available']);
    }

    // -------------------------------------------------- gate 2: replay guard

    public function test_a_replayed_callback_does_not_credit_twice(): void
    {
        $buyer = $this->buyer();
        $intent = $this->intent($buyer->id);

        $payload = json_decode($this->paystackBody([], $intent->provider_reference), true);

        $this->payments->handlePaystack($payload, signatureValid: true);
        $second = $this->payments->handlePaystack($payload, signatureValid: true);

        $this->assertSame('duplicate', $second['status']);
        $this->assertSame(
            50000,
            (int) Wallet::where('user_id', $buyer->id)->value('balance_available'),
            'a replayed webhook must not mint a second deposit'
        );
    }

    public function test_the_database_also_blocks_a_replay(): void
    {
        $buyer = $this->buyer();
        $this->wallets->ensureWallet($buyer->id, 'ghs');
        $intent = $this->payments->createIntent($buyer->id, 'paystack', 50000, 'ghs');

        $intent->update(['status' => 'paid', 'provider_event_id' => 'paystack:'.$intent->provider_reference]);

        // Bypass the service entirely: the unique index must hold on its own.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        PaymentIntent::create([
            'id' => 'pi_other',
            'user_id' => $buyer->id,
            'provider' => 'paystack',
            'amount' => 50000,
            'currency_code' => 'ghs',
            'status' => 'paid',
            'provider_event_id' => 'paystack:'.$intent->provider_reference,
        ]);
    }

    // ------------------------------------------------------------- Stripe

    public function test_a_verified_stripe_callback_credits_the_wallet(): void
    {
        $buyer = $this->buyer();
        $intent = $this->intent($buyer->id, 'stripe', 250000, 'cad');

        $payload = [
            'id' => 'evt_123',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_provider_1',
                'client_reference_id' => $intent->provider_reference,
                'amount' => 250000, // Stripe reports minor units already
                'currency' => 'cad',
            ]],
        ];

        $result = $this->payments->handleStripe($payload, signatureValid: true);

        $this->assertSame('paid', $result['status']);
        $this->assertSame(250000, (int) Wallet::where('user_id', $buyer->id)->value('balance_available'));
    }

    public function test_an_unsigned_stripe_callback_creates_no_money(): void
    {
        $buyer = $this->buyer();
        $intent = $this->intent($buyer->id, 'stripe', 250000, 'cad');

        $payload = [
            'id' => 'evt_123',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_1',
                'client_reference_id' => $intent->provider_reference,
                'amount' => 250000,
                'currency' => 'cad',
            ]],
        ];

        $result = $this->payments->handleStripe($payload, signatureValid: false);

        $this->assertSame('rejected', $result['status']);
        $this->assertSame(0, (int) Wallet::where('user_id', $buyer->id)->value('balance_available'));
    }

    // ---------------------------------------------------------- other events

    public function test_irrelevant_events_are_ignored_not_rejected(): void
    {
        $result = $this->payments->handlePaystack(
            ['event' => 'charge.failed', 'data' => ['reference' => 'x']],
            signatureValid: true
        );

        $this->assertSame('ignored', $result['status']);
    }

    // ------------------------------------------------------ currency units

    public function test_minor_unit_conversion_respects_zero_decimal_currencies(): void
    {
        // Paystack reports major units; our ledger is minor units.
        $this->assertSame(50000, WebhookVerifier::toMinorUnits(500.00));
        $this->assertSame(500, WebhookVerifier::toMinorUnits(500, WebhookVerifier::exponentFor('JPY')));
        $this->assertSame(5000, WebhookVerifier::toMinorUnits(5, WebhookVerifier::exponentFor('BHD')));
    }
}