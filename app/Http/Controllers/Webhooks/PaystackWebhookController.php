<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Payments\Paystack\PaymentGateway;
use App\Services\ParcelBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Paystack tells us money arrived.
 *
 * The signature is verified against the RAW body before anything is parsed. A
 * handler that parses first is verifying the sender's own serialisation rather
 * than what they signed, which is not verification at all.
 *
 * Anything we do not recognise is acknowledged with 200 and ignored. Returning
 * an error makes Paystack retry forever against a payload we will never act on.
 */
class PaystackWebhookController extends Controller
{
    public function __construct(
        private readonly ParcelBookingService $bookings,
        private readonly PaymentGateway $payments,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        if (! $this->payments->verifyWebhook(
            $request->getContent(),
            $request->header('x-paystack-signature'),
        )) {
            // Not acted on. No booking, no payment record, no side effects.
            Log::warning('parcel.paystack.webhook', [
                'ip' => $request->ip(),
                'reason' => 'signature mismatch',
            ]);

            return response()->json(['status' => 'ignored'], 401);
        }

        $event = $request->json('event');
        $data = $request->json('data', []);

        if ($event !== 'charge.success') {
            return response()->json(['status' => 'ignored', 'event' => $event]);
        }

        $reference = $data['reference'] ?? null;

        if (blank($reference)) {
            return response()->json(['status' => 'ignored', 'reason' => 'no reference']);
        }

        $result = $this->bookings->confirmPayment($reference, $data);

        Log::info('parcel.paystack.webhook', [
            'reference' => $reference,
            'outcome' => $result['outcome'],
        ]);

        return response()->json(['status' => $result['outcome']]);
    }
}
