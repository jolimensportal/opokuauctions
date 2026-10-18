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