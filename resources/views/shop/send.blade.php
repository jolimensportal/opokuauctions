@extends('layouts.shop')

@section('title', 'Send something to Tema — Opoku Auctions')

@section('content')
{{--
    The sender quote. No account wall: a first-time sender has a box and a
    postcode, not an intention to register with us.

    Most of the design work here is not the happy path. The rate card prices
    some origins and not others, so a refusal is a routine outcome — and each one
    names what to change rather than rendering a blank card.
--}}
<section class="mx-auto max-w-6xl px-4 sm:px-6 pt-10 pb-20">

    @if (session('status'))
        <div class="mb-6 rounded-[14px] border border-ink-200 bg-white px-5 py-4 text-sm text-ink-700" role="status">
            {{ session('status') }}

            {{-- Shown only when a link was actually minted. A button that goes
                 nowhere is worse than no button, and on an unpriced lane the
                 honest outcome is a decline, not a dead checkout. --}}
            @if (session('booking_link'))
                <a href="{{ session('booking_link') }}" target="_blank" rel="noopener noreferrer"
                   class="mt-3 inline-flex items-center rounded-full bg-brick-500 px-4 py-2.5 text-xs font-bold text-paper transition-colors hover:bg-brick-600">
                    Pay USD {{ number_format(session('booking_amount', 0) / 100, 2) }}
                </a>
                <p class="mt-2 text-[11px] text-ink-500">
                    Pay with MTN MoMo, Telecel Cash, AirtelTigo Money or card. The link is good for 24 hours.
                </p>
            @endif
        </div>
    @endif

    <h1 class="text-3xl font-extrabold tracking-tight text-ink-900">Send something to Tema</h1>
    <p class="mt-2 max-w-[62ch] text-ink-600">
        From anywhere in the United States or Canada, whether or not you bought it from us.
        Priced by weight and size, and you see the all-in figure before you pay a cent.
    </p>

    {{-- DDP stated before anyone pays, not discovered on arrival. --}}
    <div class="mt-5 rounded-[14px] border border-ink-200 bg-white px-5 py-4">
        <p class="text-sm font-bold text-ink-900">Duty, VAT and clearance included.</p>
        <p class="mt-1 text-sm text-ink-600">
            We act as importer of record, so your parcel opens with no bill at the door.
        </p>
    </div>

    <div class="mt-6 grid gap-5 lg:grid-cols-2 lg:items-start">

        {{-- ------------------------------------------------------ Input --}}
        <form method="POST" action="{{ route('send.store') }}"
              class="rounded-[14px] border border-ink-200 bg-white">
            <h2 class="border-b border-ink-200 px-5 py-3.5 text-[0.9375rem] font-bold tracking-tight">
                Tell us about the shipment
            </h2>

            <div class="space-y-5 p-5">
                <div>
                    <label for="country" class="mb-1.5 block text-[11.5px] font-semibold text-ink-900">Shipping from</label>
                    <div class="grid grid-cols-2 gap-3">
                        <select id="country" name="country" class="w-full rounded-[8px] border border-ink-300 bg-white px-3 py-2.5 text-sm focus:border-brick-500 focus:outline-none">
                            <option value="us" @selected(($input['country'] ?? 'us') === 'us')>United States</option>
                            <option value="ca" @selected(($input['country'] ?? '') === 'ca')>Canada</option>
                        </select>
                        <input id="postcode" name="postcode" value="{{ $input['postcode'] ?? '' }}"
                               placeholder="07030"
                               class="w-full rounded-[8px] border border-ink-300 bg-white px-3 py-2.5 text-sm focus:border-brick-500 focus:outline-none"
                               required>
                    </div>
                    <p class="mt-1.5 text-[11px] text-ink-500">
                        We price the origins we have corridors for. If yours is not one of them we will say so rather than guess.
                    </p>
                </div>

                <div>
                    <span class="mb-1.5 block text-[11.5px] font-semibold text-ink-900">What are you sending?</span>
                    <div class="inline-flex gap-1 rounded-full bg-ink-100 p-1">
                        @foreach ([['parcel', 'Parcel'], ['pallet', 'Pallet']] as [$value, $label])
                            <label class="cursor-pointer">
                                <input type="radio" name="unit" value="{{ $value }}"
                                       class="sr-only peer"
                                       @checked(($input['unit'] ?? 'parcel') === $value)>
                                <span class="block rounded-full px-4 py-1.5 text-xs font-semibold text-ink-600 transition-colors peer-checked:bg-white peer-checked:text-brick-600 peer-checked:shadow-sm">
                                    {{ $label }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="weight_kg" class="mb-1.5 block text-[11.5px] font-semibold text-ink-900">Weight (kg)</label>
                        <input id="weight_kg" name="weight_kg" type="number" step="0.1" min="0"
                               value="{{ $input['weight_kg'] ?? '' }}" required
                               class="w-full rounded-[8px] border border-ink-300 bg-white px-3 py-2.5 text-sm focus:border-brick-500 focus:outline-none">
                    </div>
                    <div>
                        <label for="quantity" class="mb-1.5 block text-[11.5px] font-semibold text-ink-900">Quantity</label>
                        <input id="quantity" name="quantity" type="number" min="1"
                               value="{{ $input['quantity'] ?? 1 }}"
                               class="w-full rounded-[8px] border border-ink-300 bg-white px-3 py-2.5 text-sm focus:border-brick-500 focus:outline-none">
                    </div>
                </div>

                <div>
                    <span class="mb-1.5 block text-[11.5px] font-semibold text-ink-900">Size (cm)</span>
                    <div class="grid grid-cols-3 gap-3">
                        @foreach ([['length_cm', 'Length'], ['width_cm', 'Width'], ['height_cm', 'Height']] as [$name, $label])
                            <div>
                                <label for="{{ $name }}" class="sr-only">{{ $label }}</label>
                                <input id="{{ $name }}" name="{{ $name }}" type="number" min="0"
                                       value="{{ $input[$name] ?? '' }}" placeholder="{{ $label }}"
                                       class="w-full rounded-[8px] border border-ink-300 bg-white px-3 py-2.5 text-sm focus:border-brick-500 focus:outline-none">
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-1.5 text-[11px] text-ink-500">
                        All three, because air freight is charged on the space it takes. A light, bulky box costs more than a heavy, small one.
                    </p>
                </div>

                <label class="flex cursor-pointer items-center gap-2.5">
                    <input type="checkbox" name="pickup" value="1"
                           @checked($input['pickup'] ?? false)
                           class="h-4 w-4 rounded border-ink-300 text-brick-500 focus:ring-brick-500">
                    <span class="text-sm text-ink-700">Collect it from me</span>
                </label>
                <p class="-mt-3 pl-7 text-[11px] text-ink-500">
                    Billed per stop rather than per kilo, and shown as its own line so you can see it.
                </p>

                <button class="w-full rounded-full bg-brick-500 px-5 py-3 text-sm font-bold text-paper transition-colors hover:bg-brick-600">
                    Get my price
                </button>
            </div>
        </form>

        {{-- ----------------------------------------------------- Result --}}
        <div class="lg:sticky lg:top-20">
            @if ($result === null)
                <div class="rounded-[14px] border border-ink-200 bg-white px-5 py-14 text-center">
                    <p class="text-sm font-semibold text-ink-900">Your price appears here</p>
                    <p class="mx-auto mt-1.5 max-w-[36ch] text-[13px] text-ink-600">
                        Tell us where it is going from and what is in it. We do not need your
                        name, your account, or your address to give you a figure.
                    </p>
                </div>
            @elseif ($result->isAvailable())
                @php $quote = $result->quote; @endphp
                <div class="rounded-[14px] border border-ink-200 bg-white">
                    <h2 class="border-b border-ink-200 px-5 py-3.5 text-[0.9375rem] font-bold tracking-tight">
                        Your price to Tema
                    </h2>
                    <div class="p-5">
                        @foreach ($quote->lines as $line)
                            <div class="flex items-baseline justify-between gap-4 border-b border-ink-100 py-2.5 text-sm">
                                <span class="text-ink-600">{{ $line['label'] }}</span>
                                <b class="tnum text-ink-900">${{ number_format($line['cents'] / 100, 2) }}</b>
                            </div>
                        @endforeach

                        <div class="mt-4 flex items-baseline justify-between gap-4 border-t border-ink-200 pt-4">
                            <span class="text-sm font-bold text-ink-900">Total</span>
                            <span class="tnum text-2xl font-extrabold tracking-tight text-ink-900">
                                ${{ number_format($quote->totalCents / 100, 2) }}
                            </span>
                        </div>

                        {{-- Explaining the charge is the whole point. "USD 25 to
                             move a USD 12 box" is an objection; "USD 25 because
                             your box is 48 kg of air freight" is an answer.

                             These numbers come from the quote, not from a second
                             calculation here. The page used to divide by 2000
                             while the rate row said 5000, which meant the
                             explanation could contradict the invoice. --}}
                        @php $why = $quote->explanation; @endphp
                        @if ($why && ($input['unit'] ?? 'parcel') === 'parcel' && $why->isVolumetric())
                            <p class="mt-4 rounded-[10px] border border-brick-200 bg-brick-50 px-3.5 py-3 text-xs leading-relaxed text-brick-700">
                                <strong>Why ${{ number_format($quote->totalCents / 100, 2) }}?</strong>
                                Your box bills at
                                {{ $input['length_cm'] }} &times; {{ $input['width_cm'] }} &times; {{ $input['height_cm'] }} cm,
                                which is {{ number_format($why->volumetricGrams / 1000, 1) }} kg of air freight &mdash; more than
                                the {{ number_format($why->actualKg(), 1) }} kg on the scale. We charge on whichever is
                                greater, so this box is billed at
                                {{ number_format($why->billableKg(), 1) }} kg.
                            </p>
                        @endif

                        <div class="mt-4 rounded-[10px] bg-ink-50 px-3.5 py-3 text-xs leading-relaxed text-ink-700">
                            Duty, VAT and clearance are included. Nothing to pay on arrival.
                        </div>

                        {{-- Booking is not built yet, so the page says so and
                             captures interest instead of pretending. A price
                             with a dead button is the same lie as a category
                             tile that opens the wrong board. --}}
                        <form method="POST" action="{{ route('send.reserve') }}" class="mt-4 border-t border-ink-200 pt-4">
                            <input type="hidden" name="country" value="{{ $input['country'] ?? 'us' }}">
                            <input type="hidden" name="postcode" value="{{ $input['postcode'] ?? '' }}">
                            <input type="hidden" name="unit" value="{{ $input['unit'] ?? 'parcel' }}">

                            <label for="email" class="mb-1.5 block text-[11.5px] font-semibold text-ink-900">
                                Hold this price
                            </label>
                            <div class="flex gap-2">
                                <input id="email" name="email" type="email" required
                                       placeholder="you@example.com"
                                       class="w-full rounded-[8px] border border-ink-300 bg-white px-3 py-2.5 text-sm focus:border-brick-500 focus:outline-none">
                                <button class="flex-none rounded-full bg-brick-500 px-4 py-2.5 text-xs font-bold text-paper transition-colors hover:bg-brick-600">
                                    Reserve
                                </button>
                            </div>
                            <p class="mt-1.5 text-[11px] text-ink-500">
                                Booking opens shortly. We will write before anything is charged.
                            </p>

                            {{-- The cheapest demand signal available. `/send`
                                 already produces a price, so before building a
                                 booking flow on an unvalidated assumption, find
                                 out whether anyone will pay the number at all.
                                 The message is prefilled with the quote so the
                                 conversation opens with facts instead of a
                                 re-description.

                                 Rendered only when a number is configured. A link
                                 to a placeholder sends a real enquiry into a
                                 void, which is worse than no way to enquire. --}}
                            @php
                                $waNumber = preg_replace('/\D+/', '', (string) config('opoku.whatsapp'));
                            @endphp
                            @if ($waNumber !== '')
                                @php
                                    $why = $quote->explanation;
                                    $message = collect([
                                        'Hello, I have a quote from Opoku.',
                                        '',
                                        'From: ' . strtoupper($input['country'] ?? 'us') . ' ' . ($input['postcode'] ?? ''),
                                        'Sending: ' . ($input['unit'] ?? 'parcel'),
                                        'Weight: ' . ($input['weight_kg'] ?? '?') . ' kg',
                                        ($input['length_cm'] ?? null) ? 'Size: ' . $input['length_cm'] . ' x ' . ($input['width_cm'] ?? '?') . ' x ' . ($input['height_cm'] ?? '?') . ' cm' : null,
                                        ($why?->isVolumetric()) ? 'Billed at ' . number_format($why->billableKg(), 1) . ' kg by volume.' : null,
                                        ($input['pickup'] ?? false) ? 'I need collection.' : null,
                                        '',
                                        'Quoted total: USD ' . number_format($quote->totalCents / 100, 2),
                                    ])->filter()->implode("\n");
                                    $waLink = 'https://wa.me/' . $waNumber . '?text=' . rawurlencode($message);
                                @endphp
                                <a href="{{ $waLink }}" target="_blank" rel="noopener noreferrer"
                                   class="mt-3 flex items-center justify-center gap-2 rounded-full border border-ink-300 bg-white px-4 py-2.5 text-xs font-bold text-ink-900 transition-colors hover:border-ink-900">
                                    <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.3-1.38a9.87 9.87 0 0 0 4.74 1.2h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.64-1.03-5.13-2.9-7A9.82 9.82 0 0 0 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.19 8.19 0 0 1-1.26-4.39c0-4.54 3.7-8.23 8.25-8.23 2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 0 1 2.42 5.83c0 4.54-3.7 8.23-8.24 8.23Zm4.52-6.16c-.25-.13-1.47-.72-1.69-.8-.23-.09-.39-.13-.56.12-.16.25-.64.8-.78.97-.15.16-.29.18-.53.06-.25-.13-1.05-.39-2-1.23-.74-.66-1.24-1.47-1.38-1.72-.15-.25-.02-.39.1-.51.11-.11.25-.29.37-.44.13-.15.17-.25.25-.41.09-.17.04-.31-.02-.44-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.47c-.16 0-.42.06-.64.31s-.85.83-.85 2.02.87 2.35.99 2.51c.12.16 1.71 2.61 4.14 3.66.58.25 1.03.4 1.38.51.58.19 1.11.16 1.53.1.47-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.15-1.18-.06-.11-.22-.17-.47-.29Z"/>
                                    </svg>
                                    Message us on WhatsApp
                                </a>
                                <p class="mt-1.5 text-[11px] text-ink-500">
                                    Opens a chat with your quote already written out. Nothing is charged.
                                </p>
                            @endif
                        </form>
                    </div>
                </div>
            @else
                {{-- A refusal is a routine outcome, not an error. It names what
                     to change and, where relevant, what the customer typed. --}}
                <div class="rounded-[14px] border border-brick-200 bg-white">
                    <h2 class="border-b border-brick-200 bg-brick-50 px-5 py-3.5 text-[0.9375rem] font-bold tracking-tight text-brick-700">
                        We cannot price this one yet
                    </h2>
                    <div class="p-5">
                        <p class="text-sm text-ink-700">{{ $result->message() }}</p>

                        <p class="mt-4 text-[13px] leading-relaxed text-ink-600">
                            We would rather tell you this than quote you a rate we have not priced
                            on that lane. Reserve the rate below and we will price your postcode
                            before booking opens.
                        </p>

                        <form method="POST" action="{{ route('send.reserve') }}" class="mt-4 border-t border-ink-200 pt-4">
                            <input type="hidden" name="country" value="{{ $input['country'] ?? 'us' }}">
                            <input type="hidden" name="postcode" value="{{ $input['postcode'] ?? '' }}">
                            <input type="hidden" name="unit" value="{{ $input['unit'] ?? 'parcel' }}">

                            <label for="email-refuse" class="mb-1.5 block text-[11.5px] font-semibold text-ink-900">
                                Price my postcode when you can
                            </label>
                            <div class="flex gap-2">
                                <input id="email-refuse" name="email" type="email" required
                                       placeholder="you@example.com"
                                       class="w-full rounded-[8px] border border-ink-300 bg-white px-3 py-2.5 text-sm focus:border-brick-500 focus:outline-none">
                                <button class="flex-none rounded-full bg-brick-500 px-4 py-2.5 text-xs font-bold text-paper transition-colors hover:bg-brick-600">
                                    Notify me
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</section>
@endsection