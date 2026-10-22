<?php

namespace App\Http\Controllers\Shop;

use App\Enums\IntakeMethod;
use App\Enums\ShipmentUnit;
use App\Http\Controllers\Controller;
use App\Models\RateReservation;
use App\Services\FreightQuoteService;
use App\Services\ParcelBookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The sender quote page.
 *
 * No account wall. A first-time sender has a box and a postcode, not an
 * intention to register with us, and every click between those two facts and a
 * price is somewhere they give up. Identity is asked for only once there is a
 * number they want.
 *
 * The page cannot book a shipment yet, so it does not pretend to. "Reserve this
 * rate" records that a real person wanted to ship at a real price, which is the
 * signal that decides whether building the booking flow is worth the work.
 */
class SenderQuoteController extends Controller
{
    public function __construct(private FreightQuoteService $quotes) {}

    public function create(Request $request): View
    {
        return view('shop.send', [
            'result' => null,
            'input' => $request->only([
                'country', 'postcode', 'unit', 'weight_kg', 'quantity',
                'length_cm', 'width_cm', 'height_cm', 'pickup',
            ]),
        ]);
    }

    public function store(Request $request): View
    {
        $data = $request->validate([
            'country' => ['required', Rule::in(['us', 'ca'])],
            'postcode' => ['required', 'string', 'max:16'],
            'unit' => ['required', Rule::in(['parcel', 'pallet'])],
            'weight_kg' => ['required', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            // Nullable on purpose: a missing dimension is a refusal we explain,
            // not a validation error that bounces the customer off the page.
            'length_cm' => ['nullable', 'integer', 'min:0'],
            'width_cm' => ['nullable', 'integer', 'min:0'],
            'height_cm' => ['nullable', 'integer', 'min:0'],
            'pickup' => ['nullable', 'boolean'],
        ]);

        // Kept so a reservation can be priced again server-side. The figure the
        // customer sees is never the figure we record.
        $request->session()->put('send.quote_input', $data);

        $result = $this->quotes->quote(
            postcode: $data['postcode'],
            country: $data['country'],
            destination: 'Tema',
            mode: 'air',
            unit: ShipmentUnit::from($data['unit']),
            intakeMethod: IntakeMethod::Dropoff,
            weightKg: (float) $data['weight_kg'],
            lengthCm: $data['length_cm'] ?? null,
            widthCm: $data['width_cm'] ?? null,
            heightCm: $data['height_cm'] ?? null,
            pickup: (bool) ($data['pickup'] ?? false),
        );

        return view('shop.send', [
            'result' => $result,
            'input' => $data,
        ]);
    }

    /**
     * Record interest in a quoted rate.
     *
     * An email and nothing else. This is a demand signal, not a customer
     * record — there is no password and no address because at this point we
     * have a question and nothing we have consent to hold.
     */
    public function reserve(
        Request $request,
        ParcelBookingService $bookings,
    ): RedirectResponse {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'country' => ['required', Rule::in(['us', 'ca'])],
            'postcode' => ['required', 'string', 'max:16'],
            'unit' => ['nullable', Rule::in(['parcel', 'pallet'])],
        ]);

        // Priced again here, from the quote we held server-side, rather than
        // taken from the form. A hidden field is a number anyone can edit, and
        // a demand table full of invented totals is worse than no table at all:
        // it would tell us we have demand for lanes we have not priced.
        $held = $request->session()->get('send.quote_input');

        $priced = $held ? $this->quotes->quote(
            postcode: $held['postcode'],
            country: $held['country'],
            destination: 'Tema',
            mode: 'air',
            unit: ShipmentUnit::from($held['unit']),
            intakeMethod: IntakeMethod::Dropoff,
            weightKg: (float) $held['weight_kg'],
            lengthCm: $held['length_cm'] ?? null,
            widthCm: $held['width_cm'] ?? null,
            heightCm: $held['height_cm'] ?? null,
            pickup: (bool) ($held['pickup'] ?? false),
        ) : null;

        RateReservation::create([
            'email' => $data['email'],
            'country' => $data['country'],
            'postcode' => $data['postcode'],
            'unit' => $data['unit'] ?? 'parcel',
            'quoted_total_cents' => $priced?->isAvailable() ? $priced->quote->totalCents : null,
        ]);

        // No price, no link. Minting a payment link against a lane we cannot
        // quote produces a button that takes money for something we have no
        // figure for — and the demand signal is still recorded by the
        // RateReservation above, so nothing is lost by declining to book.
        $booking = ($held && $priced?->isAvailable()) ? $bookings->open(
            quoteInput: $held,
            quotedTotalCents: (int) ($priced->quote?->totalCents ?? 0),
            rateCardRowId: (string) ($priced->quote?->rateCardRowId ?? ''),
            email: $data['email'],
            // Signed in, the parcel belongs to them and the portal can find
            // it. A guest's booking is reachable by its email only.
            userId: auth('web')->id(),
        ) : null;

        $request->session()->forget('send.quote_input');

        return redirect()
            ->route('send.create')
            ->with('status', $booking?->payment_link_url
                ? 'Payment link ready — open it below to book your parcel.'
                : 'Got it. We do not have a payable rate on that lane yet, so nothing has been charged and no card is held.')
            ->with('booking_link', $booking?->payment_link_url)
            ->with('booking_amount', $booking?->quoted_total_cents);
    }
}
