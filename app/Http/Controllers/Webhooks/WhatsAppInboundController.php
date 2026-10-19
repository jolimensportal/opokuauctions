<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\ParcelBooking;
use App\Services\ParcelBookingService;
use App\Notifications\WhatsApp\WhatsAppGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Someone replied to us on WhatsApp.
 *
 * Matched to a booking by the reference we put in the link, NOT by the sender's
 * phone number: a sender who quoted from one number and replies from another is
 * ordinary, and matching on the number would silently drop the conversation.
 *
 * The provider signature check lives in the provider adapter, not here, so that
 * swapping Twilio for another BSP does not touch this controller.
 */
class WhatsAppInboundController extends Controller
{
    public function __construct(
        private readonly ParcelBookingService $bookings,
        private readonly WhatsAppGateway $gateway,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $from = $this->normalise($request->input('From'));
        $body = trim((string) $request->input('Body'));

        if ($from === '' || $body === '') {
            return response()->json(['status' => 'ignored']);
        }

        $booking = ParcelBooking::where('reference', $this->referenceIn($body))->first();

        if (! $booking) {
            // An unknown sender is a normal thing to receive. Say so politely and
            // do not leak whether a reference exists.
            $this->gateway->send(
                $from,
                "Hello — this is Opoku. Send us a quote link from opoku.com/send and we can pick it up from there.",
                'service',
                null,
            );

            return response()->json(['status' => 'no_booking']);
        }

        if ($booking->whatsapp_to === null) {
            $booking->forceFill(['whatsapp_to' => $from])->save();
        }

        $this->gateway->send(
            $from,
            $this->replyTo($booking),
            'service',
            null,
        );

        return response()->json(['status' => 'matched', 'reference' => $booking->reference]);
    }

    private function replyTo(ParcelBooking $booking): string
    {
        return "Your Opoku quote for {$booking->postcode} is USD "
            .number_format($booking->quoted_total_cents / 100, 2)
            .". Pay here: ".$booking->payment_link_url;
    }

    private function referenceIn(string $body): string
    {
        if (preg_match('/\b([a-z0-9]{16})\b/i', $body, $m)) {
            return strtolower($m[1]);
        }

        return '';
    }

    /** WhatsApp sends `whatsapp:+15551234567` or a bare number. */
    private function normalise(?string $number): string
    {
        return ltrim((string) $number, '+');
    }
}
