{{-- HOME.

     Built to the depth of an established vehicle-export marketplace: a
     full-bleed photographic hero with the search floating over it, an inventory
     figure the business can be judged on, facet counts on every filter option,
     price-forward inventory, a published cost breakdown, and a deep footer.

     Every count and every figure is read from the database or from
     ClearanceService. Nothing here is decorative and nothing is typed into a
     template. --}}
@extends('layouts.shop')

@section('title', 'Affordable quality auto-auctions — Opoku Auctions')

@section('description', 'Affordable quality auto-auctions. Salvage and clean-title vehicles from North American garage sales, cleared and delivered at Tema Port.')

@section('content')

{{-- HERO. Full-bleed photograph, search card floating over it, inventory figure
     as the anchor. This is the pattern the category uses because it puts the
     one number that proves scale directly next to the one control a buyer came
     to use. --}}
<section class="relative bg-ink-950">
    <img src="https://images.unsplash.com/photo-1578575437130-527eed3abbec?w=2000&q=80"
         alt="A container ship being loaded at berth"
         width="2000" height="1200" fetchpriority="high" decoding="async"
         class="absolute inset-0 w-full h-full object-cover opacity-55">

    <div class="absolute inset-0 bg-gradient-to-r from-ink-950 via-ink-950/80 to-ink-950/30"></div>

    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 py-16 lg:py-24">
        <div class="grid gap-10 lg:grid-cols-12 lg:items-start">

            <div class="lg:col-span-7">
                <p class="font-mono text-xs uppercase tracking-[0.2em] text-brick-400">
                    North American garage &rarr; Tema Port
                </p>

                <h1 class="mt-4 text-[2.25rem] sm:text-5xl lg:text-6xl font-bold tracking-[-0.02em] leading-[1.05] text-paper">
                    Affordable quality
                    <span class="block text-brick-400">auto-auctions.</span>
                </h1>

                <p class="mt-6 text-lg text-ink-200 max-w-[46ch]">
                    {{ number_format($facets['totalOpen']) }} vehicles open for bidding right
                    now, out of {{ $facets['yards'] }} yards across North America.
                    Every charge is published before you bid.
                </p>

                <div class="mt-7 flex flex-wrap gap-x-6 gap-y-2 text-sm text-ink-300">
                    @foreach ([
                        'Freight from USD 1,400 shared',
                        'Deposit held until it loads',
                        'Duty and port receipted',
                    ] as $point)
                        <span class="flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-brick-400"></span>{{ $point }}
                        </span>
                    @endforeach
                </div>

                {{-- The search, floating over the photograph. Five controls,
                     because a buyer filters on exactly five things. --}}
                <form method="GET" action="{{ route('lots.index') }}"
                      class="mt-9 bg-paper rounded-[10px] border border-ink-200 shadow-xl overflow-hidden max-w-xl">
                    <div class="px-5 py-3 border-b border-ink-200 flex items-baseline justify-between">
                        <span class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-500">
                            {{ number_format($facets['totalOpen']) }} lots
                        </span>
                        <span class="text-xs tnum text-ink-400">{{ $currency->rateLabel() }}</span>
                    </div>

                    <div class="divide-y divide-ink-200">
                        @foreach ([
                            ['make', 'Make', 'Any make', $facets['makes']],
                            ['condition', 'Condition', 'Any condition', $facets['conditions']],
                            ['city', 'Auction yard', 'Any yard', $facets['yards_list']],
                        ] as [$name, $label, $placeholder, $options])
                            <div class="px-5 py-3 flex items-center gap-3">
                                <label class="text-sm text-ink-700 w-28 shrink-0" for="hero-{{ $name }}">{{ $label }}</label>
                                <select id="hero-{{ $name }}" name="{{ $name }}" class="flex-1 bg-transparent text-sm text-ink-900 focus:outline-none">
                                    <option value="">{{ $placeholder }}</option>
                                    @foreach ($options as $option)
                                        <option value="{{ $option->value }}">
                                            {{ $name === 'condition' ? str_replace('_', ' ', ucfirst($option->value)) : $option->value }}
                                            ({{ $option->count }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach

                        <div class="px-5 py-3 flex items-center gap-3">
                            <label class="text-sm text-ink-700 w-28 shrink-0" for="hero-price">Max price</label>
                            <select id="hero-price" name="price_max" class="flex-1 bg-transparent text-sm text-ink-900 focus:outline-none">
                                <option value="">Any price</option>
                                @foreach ($facets['price_bands'] as $band)
                                    <option value="{{ $band['max'] ?? '' }}">{{ $band['label'] }} ({{ $band['count'] }})</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="px-5 py-3 flex items-center gap-3">
                            <label class="text-sm text-ink-700 w-28 shrink-0" for="hero-closing">Closing</label>
                            <select id="hero-closing" name="closing" class="flex-1 bg-transparent text-sm text-ink-900 focus:outline-none">
                                <option value="">Any time</option>
                                @foreach ($facets['closing'] as $band)
                                    <option value="{{ $band->value }}">{{ $band->value }} ({{ $band->count }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="p-3 bg-ink-50">
                        <button type="submit" class="btn btn-primary w-full">Search {{ number_format($facets['totalOpen']) }} lots</button>
                    </div>
                </form>
            </div>

            <div class="lg:col-span-5 lg:pt-16">
                {{-- Closing ladder: the same partition the sidebar uses, so the
                     buyer can see the shape of the stock before filtering. --}}
                <div class="bg-ink-950/70 backdrop-blur border border-ink-800 rounded-[10px]">
                    <div class="px-5 py-3 border-b border-ink-800 text-xs uppercase tracking-[0.16em] text-ink-500">
                        What is closing
                    </div>
                    <dl class="divide-y divide-ink-800">
                        @foreach ($facets['closing'] as $band)
                            <a href="{{ route('lots.index', ['closing' => $band->value]) }}"
                               class="flex items-center justify-between gap-4 px-5 py-3 hover:bg-ink-900 transition-colors">
                                <dt class="text-sm text-ink-200">{{ $band->value }}</dt>
                                <dd class="text-sm tnum text-paper font-semibold">{{ number_format($band->count) }}</dd>
                            </a>
                        @endforeach
                    </dl>
                    <a href="{{ route('lots.index') }}"
                       class="block px-5 py-3 text-sm font-semibold text-brick-400 hover:text-brick-300 border-t border-ink-800">
                        Browse all lots &rarr;
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- MAKES, WITH COUNTS. The count beside each make is what makes this a shop
     rather than a menu: a buyer can see that Ford has 40 in stock and Dacia has
     2 before committing to anything. --}}
<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-14">
    <div class="flex flex-wrap items-baseline justify-between gap-4">
        <h2 class="text-2xl font-bold tracking-[-0.02em] text-ink-900">Browse by make</h2>
        <p class="text-sm text-ink-500">{{ $facets['makes_count'] }} makes available</p>
    </div>

    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @forelse ($facets['makes'] as $make)
            <a href="{{ route('lots.index', ['make' => $make->value]) }}"
               class="surface px-4 py-3.5 flex items-baseline justify-between gap-3 hover:border-brick-400 transition-colors">
                <span class="font-medium text-ink-900">{{ $make->value }}</span>
                <span class="text-sm tnum text-ink-500">({{ number_format($make->count) }})</span>
            </a>
        @empty
            <p class="text-ink-600">No lots are open right now.</p>
        @endforelse
    </div>
</section>

{{-- PRICE BANDS, WITH COUNTS. --}}
<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-12">
    <h2 class="text-2xl font-bold tracking-[-0.02em] text-ink-900">Browse by price</h2>

    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ($facets['price_bands'] as $band)
            <a href="{{ route('lots.index', ['price_max' => $band['max'] ?? '']) }}"
               class="surface px-4 py-4 hover:border-brick-400 transition-colors {{ $band['count'] === 0 ? 'opacity-50' : '' }}">
                <div class="text-sm font-medium text-ink-900">{{ $band['label'] }}</div>
                <div class="mt-1 text-sm tnum text-ink-500">{{ number_format($band['count']) }} lots</div>
            </a>
        @endforeach
    </div>
</section>

{{-- CONDITION EXPLAINER. Buyers cannot bid on a word they do not understand. --}}
<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-12">
    <h2 class="text-2xl font-bold tracking-[-0.02em] text-ink-900">What the conditions mean</h2>

    <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($conditions as $c)
            @php $count = $facets['conditions']->firstWhere('value', $c['key']); @endphp
            <a href="{{ route('lots.index', ['condition' => $c['key']]) }}"
               class="surface p-5 hover:border-brick-400 transition-colors">
                <div class="flex items-baseline justify-between gap-2">
                    <h3 class="font-semibold text-ink-900">{{ $c['label'] }}</h3>
                    <span class="text-sm tnum text-ink-400">{{ $count ? number_format($count->count) : 0 }}</span>
                </div>
                <p class="mt-2 text-sm text-ink-600">{{ $c['blurb'] }}</p>
            </a>
        @endforeach
    </div>
</section>

{{-- LIVE INVENTORY. --}}
@if ($featuredLots->isNotEmpty())
<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-16">
    <div class="flex flex-wrap items-baseline justify-between gap-4">
        <h2 class="text-2xl font-bold tracking-[-0.02em] text-ink-900">Closing soon</h2>
        <a href="{{ route('lots.index') }}" class="text-sm font-semibold text-brick-600 hover:text-brick-700">
            All {{ number_format($facets['totalOpen']) }} lots &rarr;
        </a>
    </div>

    <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($featuredLots as $lot)
            @include('shop.partials.lot-tile', ['lot' => $lot])
        @endforeach
    </div>
</section>
@endif

{{-- LANDED COST, from ClearanceService. --}}
@if ($landedExample)
<section class="mt-16 bg-ink-950 text-ink-100">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-16 grid gap-10 lg:grid-cols-12">
        <div class="lg:col-span-5">
            <p class="font-mono text-xs uppercase tracking-[0.2em] text-brick-400">Worked example</p>
            <h2 class="mt-3 text-3xl font-bold tracking-[-0.02em] text-paper">
                What one vehicle costs, landed
            </h2>
            <p class="mt-4 text-ink-300 max-w-[42ch]">
                From container
                <span class="tnum text-paper">{{ $landedExample['shipment']->container_no }}</span>,
                carrying {{ $landedExample['vehicleCount'] }} vehicles out of
                {{ $landedExample['shipment']->seaport_origin }}.
            </p>

            <div class="mt-8 flex items-baseline justify-between gap-4">
                <span class="text-xs uppercase tracking-[0.16em] text-ink-500">
                    Whole container, {{ $landedExample['vehicleCount'] }} vehicles
                </span>
                <span class="text-sm tnum text-ink-400">USD</span>
            </div>

            <dl class="mt-2 divide-y divide-ink-800 border-y border-ink-800">
                @foreach ([
                    ['Container freight', $landedExample['freight']],
                    ['Marine insurance', $landedExample['insurance']],
                    ['Duty, VAT and port charges', $landedExample['clearance']],
                ] as [$label, $minor])
                    <div class="flex items-baseline justify-between gap-4 py-3">
                        <dt class="text-sm text-ink-300">{{ $label }}</dt>
                        <dd class="text-sm tnum text-ink-100">{{ number_format($minor / 100, 2) }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <div class="lg:col-span-7">
            <div class="border border-ink-800 bg-ink-900/40">
                <div class="flex flex-wrap items-baseline justify-between gap-4 px-5 py-3 border-b border-ink-800">
                    <span class="text-xs uppercase tracking-[0.16em] text-ink-500">Your vehicle</span>
                    <span class="text-xs uppercase tracking-[0.16em] text-ink-500">
                        {{ $landedExample['shipment']->seaport_origin }} &rarr; {{ $landedExample['shipment']->seaport_destination }}
                    </span>
                </div>

                <div class="px-5 py-5">
                    <h3 class="text-lg font-semibold text-paper">{{ $landedExample['example']['description'] }}</h3>

                    <dl class="mt-5 divide-y divide-ink-800">
                        <div class="flex items-baseline justify-between gap-4 py-3">
                            <dt class="text-sm text-ink-300">Hammer price</dt>
                            <dd class="text-sm tnum text-ink-100">
                                {{ number_format($landedExample['example']['purchase_price'] / 100, 2) }}
                                <span class="text-ink-500">USD</span>
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 py-3">
                            <dt class="text-sm text-ink-300">Your share of freight, insurance, duty and port</dt>
                            <dd class="text-sm tnum text-ink-100">
                                {{ number_format($landedExample['share'] / 100, 2) }}
                                <span class="text-ink-500">USD</span>
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4 pt-4">
                            <dt class="font-semibold text-paper">Total cost at Tema</dt>
                            <dd class="text-2xl font-bold tnum text-brick-400">
                                {{ number_format($landedExample['example']['landed_cost'] / 100, 2) }}
                                <span class="text-base font-semibold">USD</span>
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-5 pt-4 border-t border-ink-800">
                        <div class="text-xs text-ink-500">At today's rate</div>
                        <div class="mt-1 text-lg tnum text-paper">
                            {{ $currency->format($landedExample['example']['landed_cost']) }}
                        </div>
                    </div>

                    <p class="mt-4 text-xs text-ink-500">
                        That share is what it actually cost to move this one vehicle,
                        divided across the {{ $landedExample['vehicleCount'] }} in the container.
                        The cedi figure moves with the rate; the USD figure is what the
                        forwarder invoices, and it is fixed once your deposit is paid.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
@endif

{{-- GUARANTEES. Facts, not adjectives. --}}
<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-16">
    <h2 class="text-2xl font-bold tracking-[-0.02em] text-ink-900">Why buy through us</h2>

    <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Every lot photographed at the yard', 'Damage is recorded and photographed before the lot opens, so what you are bidding on is documented rather than described.'],
            ['Your deposit is held, not spent', 'It sits in your wallet against that one vehicle and only moves when that vehicle loads.'],
            ['Duty and port receipted', 'We pay them on your behalf and give you the receipts. Nobody quotes you a percentage and never shows the bill.'],
            ['One contact from bid to gate-out', 'WhatsApp updates at every milestone. You are not passed between three brokers.'],
        ] as $i => [$title, $body])
            <div class="surface p-5">
                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-brick-600 tnum">
                    {{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}
                </div>
                <h3 class="mt-2 font-semibold text-ink-900 leading-snug">{{ $title }}</h3>
                <p class="mt-2 text-sm text-ink-600">{{ $body }}</p>
            </div>
        @endforeach
    </div>
</section>

{{-- LOT TYPES. --}}
<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-16">
    <h2 class="text-2xl font-bold tracking-[-0.02em] text-ink-900">We buy more than cars</h2>

    <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        {{--
            Every tile below opens a genuinely different board, and every claim
            is one the product actually makes.

            This used to be four links that mostly pointed at /lots with no
            filter at all, so three of them opened the same board, one pointed
            at an article rather than a board, and the Pallets tile promised "no
            bidding and no waiting for a lot to close" when a pallet is a lot
            and does bid. A tile that leads somewhere empty is a dead end on the
            front page; copy that lies is worse than no tile.

            There is no Pallets tile yet because pallets are not a category that
            can be filtered — a pallet and a bed frame are both kind=goods, and
            nothing on a lot records that it is a pallet. It returns when pallet
            inventory actually exists, rather than as a link to a board that
            would show bed frames.
        --}}
        @foreach ([
            ['Vehicles', 'Cars, SUVs and pickups from North American salvage auctions and garage sales.', 'lots.index', ['kind' => 'vehicle'], 'photo-1533473359331-0135ef1b58bf'],
            ['Garage goods', 'Individual items out of whole-garage cleanouts. One item, one lot, one price.', 'lots.index', ['kind' => 'goods'], 'photo-1586528116311-ad8dd3c8310d'],
            ['Salvage write-offs', 'Accident-damaged and insurance write-off vehicles, graded and photographed.', 'lots.index', ['provenance' => 'salvage_auction'], 'photo-1625047509248-ec889cbff17f'],
            ['Garage-sale lots', 'A whole garage bought as one lot, then resold piece by piece.', 'lots.index', ['provenance' => 'garage_sale'], 'photo-1449965408869-eaa3f722e40d'],
        ] as [$title, $blurb, $routeName, $params, $photo])
            <a href="{{ route($routeName, $params) }}" class="surface overflow-hidden group">
                <div class="aspect-[3/2] overflow-hidden bg-ink-100">
                    <img src="https://images.unsplash.com/{{ $photo }}?w=600&q=80"
                         alt="" width="600" height="400" loading="lazy" decoding="async"
                         class="w-full h-full object-cover group-hover:scale-[1.03] transition-transform duration-500">
                </div>
                <div class="p-5">
                    <h3 class="font-semibold text-ink-900 group-hover:text-brick-600 transition-colors">{{ $title }}</h3>
                    <p class="mt-1.5 text-sm text-ink-600">{{ $blurb }}</p>
                </div>
            </a>
        @endforeach
    </div>
</section>

{{-- PROCESS. --}}
<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-16">
    <h2 class="text-2xl font-bold tracking-[-0.02em] text-ink-900">How it works</h2>

    <ol class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ([
            ['Search', 'Filter by make, condition, yard and price. Every option shows how many lots are behind it.'],
            ['Bid', 'Pay a deposit into your wallet and place the bid. A late bid pushes the close back two minutes.'],
            ['Pay balance', 'Freight, duty and handling before it loads. Then the container sails.'],
            ['Clearing', 'We file at GCNet, pay duty and port charges, and release the vehicle at Tema.'],
            ['Collect', 'You collect at Tema Port. We message you the day the release order is issued.'],
        ] as $i => [$step, $body])
            <li class="surface p-5">
                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-brick-600 tnum">
                    {{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}
                </div>
                <h3 class="mt-2 font-semibold text-ink-900">{{ $step }}</h3>
                <p class="mt-1.5 text-sm text-ink-600">{{ $body }}</p>
            </li>
        @endforeach
    </ol>

    <a href="{{ route('how-it-works') }}" class="btn btn-ghost mt-6">Read the full terms</a>
</section>

{{-- REQUEST A CAR. The service a marketplace offers when inventory runs short. --}}
<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-16">
    <div class="surface overflow-hidden grid lg:grid-cols-2">
        <div class="p-8 sm:p-10">
            <h2 class="text-2xl font-bold tracking-[-0.02em] text-ink-900">
                Name the car. We will go and buy it.
            </h2>
            <p class="mt-3 text-ink-600 max-w-[46ch]">
                If it is not in our lots, send us the year, make and model and your
                ceiling. We bid for it at the yard and quote you a fixed price, which
                is faster than waiting for an auction to close.
            </p>
            <a href="{{ route('register') }}" class="btn btn-primary mt-7">Request a car</a>
        </div>
        <img src="https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?w=1000&q=80"
             alt="A mechanic inspecting a vehicle at a yard" width="1000" height="800" loading="lazy" decoding="async"
             class="w-full h-56 lg:h-full object-cover">
    </div>
</section>

{{-- FAQ. --}}
<section class="border-t border-ink-200 mt-16 bg-ink-50/70">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-16">
        <h2 class="text-2xl font-bold tracking-[-0.02em] text-ink-900">Questions buyers ask us</h2>

        <dl class="mt-8 grid gap-x-10 gap-y-7 md:grid-cols-2">
            @foreach ([
                ['Can I inspect the car before bidding?',
                 'No, it is on the other side of the Atlantic. What we do instead is photograph every lot at the yard and publish the damage report, and we only list a car once you have seen both.'],
                ['When is my deposit released?',
                 'When your vehicle loads. It stays in your wallet as a named balance rather than being spent, and every movement shows in a ledger you can read.'],
                ['Can I pay in cedis?',
                 'Yes. You can hold your wallet in GHS or USD and pay in whichever you prefer. The platform settles with the forwarder in USD, and the rate you get is locked at your deposit.'],
                ['What if the car arrives damaged or not as described?',
                 'Tell us before you accept it. We photograph it, open a claim with the forwarder, and your deposit is not released until the claim is settled. Only an Owner can release it.'],
            ] as $i => [$q, $a])
                <div class="{{ $i ? 'md:border-t md:border-ink-200 md:pt-6' : '' }}">
                    <dt class="font-semibold text-ink-900">{{ $q }}</dt>
                    <dd class="mt-1.5 text-sm text-ink-600">{{ $a }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</section>

{{-- CTA. --}}
<section class="mx-auto max-w-7xl px-4 sm:px-6 py-16">
    <div class="flex flex-wrap items-center justify-between gap-6">
        <div>
            <h2 class="text-2xl font-bold tracking-[-0.02em] text-ink-900">Create an account to bid</h2>
            <p class="mt-2 text-ink-600">Fund your wallet by card or Mobile Money, then bid the same evening.</p>
        </div>
        <div class="flex gap-3">
            <a href="{{ route('register') }}" class="btn btn-primary">Create account</a>
            <a href="{{ route('lots.index') }}" class="btn btn-ghost">Browse {{ number_format($facets['totalOpen']) }} lots</a>
        </div>
    </div>
</section>

@endsection