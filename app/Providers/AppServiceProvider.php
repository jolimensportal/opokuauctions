<?php

namespace App\Providers;

use App\Services\DisplayCurrency;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The transport is resolved, never hard-coded, so the send path can be
        // tested without a provider and swapped later without touching callers.
        $this->app->singleton(\App\Notifications\WhatsApp\WhatsAppGateway::class, function () {
            $fake = (bool) env('WHATSAPP_FAKE', false);

            if ($fake) {
                return new \App\Notifications\WhatsApp\FakeWhatsAppGateway();
            }

            return new \App\Notifications\WhatsApp\TwilioWhatsAppGateway(
                (string) env('TWILIO_ACCOUNT_SID', ''),
                (string) env('TWILIO_AUTH_TOKEN', ''),
                (string) env('TWILIO_WHATSAPP_FROM', 'whatsapp:+14155238886'),
            );
        });

        // The payment rail, resolved the same way. The WhatsApp provider is
        // still an open decision, so nothing outside this binding and the two
        // adapters may name a provider.
        $this->app->singleton(\App\Payments\Paystack\PaymentGateway::class, function () {
            $key = (string) env('PAYSTACK_SECRET_KEY', '');

            // A missing key must not silently produce a live-charging gateway
            // holding an empty secret, which would sign nothing and then reject
            // every genuine webhook - a checkout that takes money and books
            // nothing, which is the worst possible failure.
            if ((bool) env('PAYSTACK_FAKE', false) || blank($key)) {
                return new \App\Payments\Paystack\FakePaymentGateway();
            }

            return new \App\Payments\Paystack\PaystackGateway($key);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Money formatting is needed by nearly every view in the shop. Binding it
        // here rather than passing it around keeps every price on the site
        // formatted by the same code, so USD and GHS can never disagree.
        $this->app->singleton(DisplayCurrency::class, fn ($app) => new DisplayCurrency(
            $app->make(\App\Services\FxService::class),
        ));

        View::composer('*', function ($view) {
            $view->with('currency', $this->app->make(DisplayCurrency::class));
        });

        //
    }
}
