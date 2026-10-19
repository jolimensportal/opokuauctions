<?php

namespace Tests;

use App\Notifications\WhatsApp\FakeWhatsAppGateway;
use App\Notifications\WhatsApp\WhatsAppGateway;
use App\Payments\Paystack\FakePaymentGateway;
use App\Payments\Paystack\PaymentGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Nothing in this suite talks to a real provider.
     *
     * Paystack test keys were sitting in .env and three reserve tests quietly
     * started calling the live API — which meant the suite depended on network
     * reachability, on somebody else's rate limits, and on a merchant account
     * that happened to have the right currencies enabled. It also meant a
     * failing assertion could be a network problem rather than a code problem.
     *
     * Bound here rather than per test class so that adding real credentials to
     * .env can never make the suite reach outward.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $whatsapp = new FakeWhatsAppGateway();
        $payments = new FakePaymentGateway();

        $this->app->instance(WhatsAppGateway::class, $whatsapp);
        $this->app->instance(FakeWhatsAppGateway::class, $whatsapp);
        $this->app->instance(PaymentGateway::class, $payments);
        $this->app->instance(FakePaymentGateway::class, $payments);
    }
}