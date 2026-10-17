@extends('layouts.shop')

@section('title', $lot->title.' — Opoku Auctions')

@section('content')
<section class="mx-auto max-w-6xl px-4 sm:px-6 pt-10 pb-20">

    <a href="{{ route('lots.index') }}" class="text-sm text-ink-500 hover:text-ink-900">&larr; All lots</a>

    <div class="mt-6 grid gap-10 lg:grid-cols-12">

        <div class="lg:col-span-7">
            <img src="{{ $lot->primaryPhoto() ?? 'https://picsum.photos/seed/opoku-lot-'.$lot->id.'/1200/900' }}"
                 alt="{{ $lot->title }} in {{ $lot->city }}"
                 width="1200" height="900" fetchpriority="high" decoding="async"
                 class="w-full h-72 sm:h-[28rem] object-cover rounded-[10px] border border-ink-200">

            {{-- The facts a buyer weighs this lot by, which differ by kind: a
                 pallet has no mileage or VIN, and a bed frame has neither. The
                 shared facts sit at the end so both kinds answer location and
                 condition the same way. --}}
            @php
                $facts = [];

                if ($lot->vehicleDetail) {
                    $facts[] = ['VIN', $lot->vehicleDetail->vin ?: 'Not recorded'];
                    $facts[] = ['Year', $lot->vehicleDetail->year];
                    $facts[] = ['Mileage', $lot->vehicleDetail->odometer
                        ? number_format($lot->vehicleDetail->odometer).' mi'
                        : 'Not recorded'];
                    $facts[] = ['Primary damage', $lot->vehicleDetail->damage_primary ?: 'Not recorded'];
                } else {
                    $goods = $lot->goodsDetail;

                    $facts[] = ['Unit', $goods?->unit_label ?: str_replace('_', ' ', $lot->kind)];

                    if ($goods?->manifest_count) {
                        $facts[] = ['Pieces', number_format($goods->manifest_count)];
                    }
                }

                if ($lot->weight_kg) {
                    $facts[] = ['Weight', number_format((float) $lot->weight_kg).' kg'];
                }

                $facts[] = ['Location', $lot->city];
                $facts[] = ['Condition', str_replace('_', ' ', $lot->condition)];
            @endphp

            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                @foreach ($facts as [$label, $value])
                    <div class="flex items-baseline justify-between gap-4 border-b border-ink-200 pb-2.5">
                        <span class="text-sm text-ink-500">{{ $label }}</span>
                        <span class="text-sm font-medium text-ink-900 tnum">{{ $value }}</span>
                    </div>
                @endforeach
            </div>

            @if ($lot->description)
                <p class="mt-6 text-sm text-ink-700 leading-relaxed">{{ $lot->description }}</p>
            @endif
        </div>

        {{-- The buying column. Sticky on desktop because it is the thing being
             read while the photos are being looked at. --}}
        <div class="lg:col-span-5">
            <div class="lg:sticky lg:top-24 surface p-6">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.14em] bg-ink-950 text-paper px-2.5 py-1 rounded-[4px]">
                        {{ str_replace('_', ' ', $lot->condition) }}
                    </span>
                    @if ($lot->isAcceptingBids())
                        <span class="text-xs font-semibold uppercase tracking-[0.14em] text-brick-600">Live</span>
                    @endif
                </div>

                <h1 class="mt-4 text-3xl font-extrabold tracking-tight text-ink-900">
                    {{ $lot->title }}
                </h1>

                {{-- No invented lot number. Nothing in the schema names one, and
                     slicing the primary key produced "Lot _DEMO_02" on demo
                     rows. The VIN is the identifier a buyer can quote — and only
                     a vehicle has one. --}}
                <p class="mt-1 text-sm text-ink-500">
                    {{ $lot->city }}
                    @if ($lot->vehicleDetail?->vin)
                        &middot; VIN <span class="tnum">{{ $lot->vehicleDetail->vin }}</span>
                    @endif
                </p>

                <div class="mt-6 pt-6 border-t border-ink-200">
                    <div class="text-xs uppercase tracking-[0.14em] text-ink-500">Current bid</div>
                    <div class="mt-1 text-4xl font-extrabold tracking-tight tnum text-ink-900">
                        {{ number_format($lot->current_bid / 100, 2) }}
                        <span class="text-base font-semibold text-ink-500">{{ strtoupper($lot->currency_code) }}</span>
                    </div>
                    <p class="mt-2 text-sm text-ink-600">
                        @if ($lot->reserve_price <= 0)
                            No reserve: this lot sells to the highest bidder.
                        @elseif ($lot->current_bid < $lot->reserve_price)
                            Reserve not met yet, so the seller may not sell at this price.
                        @else
                            Reserve met.
                        @endif
                        Minimum next bid
                        <span class="tnum font-semibold text-ink-900">
                            {{ number_format($lot->minimumNextBid() / 100, 2) }}
                        </span>.
                    </p>
                </div>

                <div class="mt-6 pt-6 border-t border-ink-200">
                    <div class="text-xs uppercase tracking-[0.14em] text-ink-500">Closes</div>
                    <p class="mt-1 tnum text-ink-900 font-semibold">
                        {{ $lot->ends_at->format('j M Y, H:i') }}
                    </p>
                    <p class="text-sm text-ink-500">
                        A bid in the last {{ \App\Services\AntiSnipe::WINDOW_MINUTES }}
                        minutes pushes the close back by the same amount
                        @if ($lot->extensions > 0)
                            &mdash; this lot has been extended {{ $lot->extensions }} time{{ $lot->extensions === 1 ? '' : 's' }}.
                        @endif
                    </p>
                </div>

                {{-- What this lot costs all-in. Every figure is booked money
                     from the clearance ledger, never a typed template. A lot
                     not yet on a container has no landed cost, and the section
                     stays silent rather than guessing. --}}
                @if ($landed)
                    @php $row = $landed['row']; @endphp
                    <div class="mt-6 pt-6 border-t border-ink-200">
                        <div class="text-xs uppercase tracking-[0.14em] text-ink-500">Landed cost</div>
                        <dl class="mt-3 space-y-1.5">
                            <div class="flex items-baseline justify-between gap-4">
                                <dt class="text-sm text-ink-600">Freight to Tema</dt>
                                <dd class="text-sm text-ink-900 tnum">{{ number_format($row['freight'] / 100, 2) }}</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-4">
                                <dt class="text-sm text-ink-600">Insurance</dt>
                                <dd class="text-sm text-ink-900 tnum">{{ number_format($row['insurance'] / 100, 2) }}</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-4">
                                <dt class="text-sm text-ink-600">Clearance, duty and port</dt>
                                <dd class="text-sm text-ink-900 tnum">{{ number_format($row['clearance'] / 100, 2) }}</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-4">
                                <dt class="text-sm text-ink-600">
                                    This lot&rsquo;s share of freight, insurance and clearance
                                    @if (($landed['vehicleCount'] ?? 0) > 1)
                                        <span class="block text-xs text-ink-500">
                                            Container costs divided across {{ $landed['vehicleCount'] }} vehicles.
                                        </span>
                                    @endif
                                </dt>
                                <dd class="text-sm font-medium text-ink-900 tnum">{{ number_format($row['freight_and_clearance_share'] / 100, 2) }}</dd>
                            </div>
                        </dl>

                        <div class="mt-3 pt-3 border-t border-ink-200 flex items-baseline justify-between gap-4">
                            <span class="text-sm font-semibold text-ink-900">
                                Landed cost if won at {{ number_format($row['purchase_price'] / 100, 2) }}
                            </span>
                            <span class="text-lg font-bold text-ink-900 tnum">{{ number_format($row['landed_cost'] / 100, 2) }}</span>
                        </div>
                    </div>
                @endif

                @auth
                    @if ($lot->isAcceptingBids())
                        <form method="POST" action="{{ route('lots.bid', $lot) }}" class="mt-6 pt-6 border-t border-ink-200">
                            @csrf
                            <label class="label" for="amount">Your bid ({{ strtoupper($lot->currency_code) }})</label>
                            <div class="flex gap-2">
                                <input id="amount" name="amount" type="number" step="0.01" required
                                       min="{{ $lot->minimumNextBid() / 100 }}"
                                       value="{{ $lot->minimumNextBid() / 100 }}"
                                       class="field tnum">
                                <button type="submit" class="btn btn-primary">Bid</button>
                            </div>

                            @error('amount')
                                <p class="mt-2 text-sm text-brick-700">{{ $message }}</p>
                            @enderror
                            @error('bid')
                                <p class="mt-2 text-sm text-brick-700">{{ $message }}</p>
                            @enderror
                        </form>
                    @else
                        <p class="mt-6 pt-6 border-t border-ink-200 text-sm text-ink-600">
                            This lot is no longer open for bidding.
                        </p>
                    @endif
                @else
                    <div class="mt-6 pt-6 border-t border-ink-200">
                        <p class="text-sm text-ink-600">Sign in and fund your wallet to bid.</p>
                        <a href="{{ route('login') }}" class="btn btn-primary mt-4 w-full">Sign in to bid</a>
                    </div>
                @endauth

                {{-- Freight to Tema, and whether we fetch it from the port.
                     Server-rendered for bring-yourself, which is the default and
                     costs nothing, so the card is correct before any script
                     runs at all. The toggle asks for the other case only when a
                     buyer actually clicks it.

                     An unquotable lot says so. It does not show a zero and it
                     does not show a default weight's worth of freight: a zero
                     reads as "free" and a default weight reads as a real number,
                     and neither is true. --}}
                <div class="mt-6 pt-6 border-t border-ink-200"
                     data-freight
                     data-endpoint="{{ route('lots.freight-estimate', $lot) }}">

                    <div class="flex items-baseline justify-between gap-4">
                        <span class="text-xs uppercase tracking-[0.14em] text-ink-500">Freight to Tema</span>
                        <span class="text-xs text-ink-500">duty cleared separately</span>
                    </div>

                    <div class="mt-2 text-2xl font-extrabold tracking-tight tnum text-ink-900">
                        <span data-freight-amount @class([
                            'text-ink-400' => ! ($freight?->available),
                        ])>{{ $freight?->available ? number_format($freight->totalCents / 100, 2) : 'Not quoted yet' }}</span>
                        <span class="text-sm font-semibold text-ink-500" data-freight-currency
                              @if (! ($freight?->available)) hidden @endif>USD</span>
                    </div>

                    <p class="mt-1 text-sm text-ink-500" data-freight-detail>
                        @if ($freight?->available)
                            {{ number_format($freight->freightCents / 100, 2) }} freight &middot;
                            {{ number_format($freight->insuranceCents / 100, 2) }} insurance
                            on a <span class="tnum">{{ number_format($freight->weightKg, 0) }} kg</span> car
                        @else
                            Ask us and we will price this car before you bid.
                        @endif
                    </p>

                    {{-- Collect-it-yourself is free and is what the card opens
                         on. The surcharge pays for a convenience, so it is never
                         a default the buyer has to notice and opt out of. --}}
                    <div class="mt-4 flex gap-2" role="group" aria-label="Collection">
                        <button type="button"
                                data-pickup="0"
                                aria-pressed="true"
                                class="flex-1 rounded-[6px] border border-ink-900 bg-ink-900 px-3 py-2 text-sm font-semibold text-paper">
                            Collect it myself
                        </button>
                        <button type="button"
                                data-pickup="1"
                                aria-pressed="false"
                                class="flex-1 rounded-[6px] border border-ink-200 px-3 py-2 text-sm font-semibold text-ink-600 hover:border-ink-400">
                            Collect it for me
                        </button>
                    </div>
                </div>

                <script>
                    // Progressive enhancement only: the figure above is already
                    // correct. This only swaps in the pickup figure when asked.
                    (function () {
                        var card = document.querySelector('[data-freight]');
                        if (!card) return;

                        var amount = card.querySelector('[data-freight-amount]');
                        var currency = card.querySelector('[data-freight-currency]');
                        var detail = card.querySelector('[data-freight-detail]');
                        var buttons = card.querySelectorAll('[data-pickup]');

                        var money = function (cents) {
                            return (cents / 100).toLocaleString(undefined, {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2,
                            });
                        };

                        function paint(quote) {
                            currency.hidden = ! quote.available;
                            amount.classList.toggle('text-ink-400', ! quote.available);

                            amount.textContent = quote.available
                                ? money(quote.totalCents)
                                : 'Not quoted yet';

                            detail.textContent = quote.available
                                ? money(quote.freightCents) + ' freight · '
                                    + money(quote.insuranceCents) + ' insurance on a '
                                    + Math.round(quote.weightKg).toLocaleString() + ' kg car'
                                : 'Ask us and we will price this car before you bid.';
                        }

                        function press(on) {
                            buttons.forEach(function (button) {
                                var isOn = button.dataset.pickup === on;
                                button.setAttribute('aria-pressed', isOn ? 'true' : 'false');
                                button.classList.toggle('border-ink-900', isOn);
                                button.classList.toggle('bg-ink-900', isOn);
                                button.classList.toggle('text-paper', isOn);
                                button.classList.toggle('border-ink-200', ! isOn);
                                button.classList.toggle('text-ink-600', ! isOn);
                            });
                        }

                        buttons.forEach(function (button) {
                            button.addEventListener('click', function () {
                                press(button.dataset.pickup);

                                fetch(card.dataset.endpoint + '?pickup=' + button.dataset.pickup, {
                                    headers: { Accept: 'application/json' },
                                })
                                    .then(function (response) { return response.json(); })
                                    .then(paint)
                                    .catch(function () {
                                        // Leave the server-rendered figure
                                        // standing. A failed request is not a
                                        // reason to tell a buyer their car is
                                        // free, or to blank out a price.
                                    });
                            });
                        });
                    })();
                </script>
            </div>
        </div>
    </div>

    {{-- Bid history. A short list, not a table dump: enough to judge the
         activity, not so much it becomes the page. --}}
    @if ($bids->isNotEmpty())
        <div class="mt-14 max-w-2xl">
            <h2 class="text-xl font-extrabold tracking-tight text-ink-900">Bid history</h2>
            <dl class="mt-4 divide-y divide-ink-200 border-t border-ink-200">
                @foreach ($bids as $bid)
                    <div class="flex items-baseline justify-between gap-4 py-2.5">
                        <dt class="text-sm text-ink-600">
                            {{ $bid->status === 'WINNING' ? 'Leading' : 'Outbid' }}
                            <span class="text-ink-400 tnum">· {{ $bid->created_at->diffForHumans() }}</span>
                        </dt>
                        <dd class="tnum font-semibold text-ink-900">{{ number_format($bid->amount / 100, 2) }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif
</section>
@endsection
