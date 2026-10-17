@extends('layouts.shop')

@section('title', 'Preview — what the buyer sees')

@section('content')
<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-10 pb-24">

    <p class="text-xs uppercase tracking-[0.14em] text-ink-500">Throwaway design preview</p>
    <h1 class="mt-2 text-3xl font-bold tracking-[-0.02em] text-ink-900">
        What the buyer sees if pallets and clearance are real
    </h1>
    <p class="mt-2 max-w-2xl text-ink-600">
        Rendered in the real shell, the real fonts and the real component classes so it can be
        judged as a page rather than a picture of one.
    </p>

    {{-- ============================================================ 1. TILES --}}
    <div class="mt-14">
        <p class="text-xs uppercase tracking-[0.14em] text-brick-600">01 &middot; Homepage</p>
        <h2 class="mt-2 text-2xl font-bold tracking-[-0.02em] text-ink-900">We buy more than cars</h2>
        <p class="mt-2 max-w-2xl text-ink-600">
            Same section, same layout. Each tile now filters a real board instead of dropping the
            buyer onto cars.
        </p>

        <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Vehicles', 'Cars, SUVs and pickups out of North American garage sales and salvage auctions.', '1533473359331-0135ef1b58bf', '148 open'],
                ['Pallets', 'Whole pallets and truckloads. Timed auction — highest bid when the clock runs out.', '1586528116311-ad8dd3c8310d', '0 open'],
                ['Garage lots', 'Individual pieces out of residential garage and estate sales, photographed one by one.', '1625047509248-ec889cbff17f', '0 open'],
                ['Foreclosure', 'Vehicles repossessed in North America and sold to recover a lender\'s loss.', '1449965408869-eaa3f722e40d', '0 open'],
            ] as [$title, $blurb, $photo, $count])
                <a href="#" class="surface overflow-hidden group">
                    <div class="aspect-[3/2] overflow-hidden bg-ink-100">
                        <img src="https://images.unsplash.com/photo-{{ $photo }}?w=600&q=80" alt=""
                             width="600" height="400" loading="lazy" decoding="async"
                             class="w-full h-full object-cover group-hover:scale-[1.03] transition-transform duration-500">
                    </div>
                    <div class="p-5">
                        <h3 class="font-semibold text-ink-900 group-hover:text-brick-600 transition-colors">
                            {{ $title }}
                        </h3>
                        <p class="mt-1.5 text-sm text-ink-600">{{ $blurb }}</p>
                        <p class="mt-3 text-xs font-semibold text-brick-600 tnum">{{ $count }} &rarr;</p>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-5 border-l-2 border-brick-500 pl-4">
            <p class="text-sm text-ink-700">
                <strong>Only vehicles has a real count.</strong> The other three all read
                <span class="tnum">0 open</span>, because those categories do not exist yet — and
                all three currently link to the same vehicle board. Clicking "Pallets" today shows
                a buyer a page of cars.
            </p>
        </div>
    </div>

    {{-- ============================================================ 2. BOARD --}}
    <div class="mt-20">
        <p class="text-xs uppercase tracking-[0.14em] text-brick-600">02 &middot; The board</p>
        <h2 class="mt-2 text-2xl font-bold tracking-[-0.02em] text-ink-900">One board, mixed kinds</h2>
        <p class="mt-2 max-w-2xl text-ink-600">
            Same row, same countdown, same bid button. A buyer filters down to pallets or does not.
        </p>

        <div class="surface mt-6 overflow-hidden">
            <div class="flex items-center justify-between gap-4 border-b border-ink-200 bg-ink-50 px-5 py-3">
                <span class="text-sm font-semibold text-ink-900">All lots</span>
                <span class="text-xs text-ink-500 tnum">148 vehicles &middot; 0 pallets &middot; 0 items</span>
            </div>

            @foreach ([
                ['2018 Audi RS4 Quattro', 'Vehicle &middot; Clean title &middot; Newark', '$2,150', 'closes in 6 hr', '1533473359331-0135ef1b58bf'],
                ['2021 Ford F-150 XLT SuperCrew', 'Vehicle &middot; Salvage &middot; Detroit', '$3,850', 'closes in 19 hr', '1551830820-330a71b99659'],
                ['Pallet 412 — tissues, 480 cartons', 'Pallet &middot; Grade A &middot; Newark &middot; <span class="tnum">610 kg</span>', '$380', 'closes in 2 days', null],
                ['Solid oak dining chair', 'Garage item &middot; Working &middot; Elm St', '$60', 'closes in 4 hr', null],
            ] as $i => [$title, $meta, $price, $closing, $photo])
                <div class="grid grid-cols-12 gap-4 items-center border-b border-ink-100 px-5 py-4 last:border-b-0">
                    <div class="col-span-2 sm:col-span-1">
                        @if ($photo)
                            <img src="https://images.unsplash.com/photo-{{ $photo }}?w=240&q=80" alt=""
                                 width="240" height="160" loading="lazy" decoding="async"
                                 class="aspect-[3/2] w-full rounded-[6px] bg-ink-100 object-cover">
                        @else
                            <div class="flex aspect-[3/2] w-full items-center justify-center rounded-[6px] bg-ink-100 text-[10px] uppercase tracking-[0.08em] text-ink-400">
                                Photo
                            </div>
                        @endif
                    </div>
                    <div class="col-span-10 sm:col-span-6">
                        <p class="truncate font-semibold text-ink-900">{{ $title }}</p>
                        <p class="mt-0.5 text-xs text-ink-500">{!! $meta !!}</p>
                    </div>
                    <div class="col-span-6 text-right text-lg font-bold text-ink-900 tnum sm:col-span-2">
                        {{ $price }}
                    </div>
                    <div class="col-span-6 text-right text-xs text-ink-500 sm:col-span-2">
                        {{ $closing }}
                    </div>
                    <div class="col-span-12 text-right sm:col-span-1">
                        <button class="btn btn-primary !px-4 !py-2 !text-sm">Bid</button>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="mt-4 max-w-2xl text-sm text-ink-600">
            Only the title, the kind and the weight differ. Weight is the reason the freight figure
            further down is honest for a pallet and for a chair.
        </p>
    </div>

    {{-- ========================================================== 3. LOT PAGES --}}
    <div class="mt-20">
        <p class="text-xs uppercase tracking-[0.14em] text-brick-600">03 &middot; Lot pages</p>
        <h2 class="mt-2 text-2xl font-bold tracking-[-0.02em] text-ink-900">What changes between kinds</h2>
        <p class="mt-2 max-w-2xl text-ink-600">
            Countdown, deposit and freight are the same code on all three. The fact grid is the only
            thing that differs.
        </p>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">

            @foreach ([
                [
                    'Vehicle', '1606664515524-ed2f786a0bd6', '2018 Audi RS4 Quattro',
                    'Newark &middot; VIN WAUCNAF51JA123456',
                    [['Year','2018','tnum'], ['Make / model','Audi RS4',''], ['Mileage','184,230 mi','tnum'], ['Primary damage','None recorded','']],
                    '$615.80', '$508.80 freight &middot; $107.00 insurance on a <span class="tnum">1,590 kg</span> car',
                    '$2,150.00', false,
                ],
                [
                    'Pallet', '1586528116311-ad8dd3c8310d', 'Pallet 412 — tissues, 480 cartons',
                    'Newark &middot; overstock from a US distributor',
                    [['Units','480 cartons','tnum'], ['Grade','A — overstock',''], ['Weight','610 kg','tnum'], ['Pallet size','120 × 100 × 140 cm','tnum']],
                    '$292.20', '$195.20 freight &middot; $107.00 insurance on a <span class="tnum">610 kg</span> pallet',
                    '$380.00', false,
                ],
                [
                    'Garage item', '1594224457860-23bdb45f8e3d', 'Solid oak dining chair',
                    'Garage sale, Elm Street &middot; one of 340 pieces',
                    [['Condition','Working',''], ['Weight','5.2 kg','tnum'], ['Source','Garage sale',''], ['Ships with','Next container','']],
                    '$357.00', 'Minimum consignment charge applies. $1.66 freight &middot; $107.00 insurance on <span class="tnum">5.2 kg</span>',
                    '$60.00', true,
                ],
            ] as [$kind, $photo, $title, $sub, $facts, $freight, $freightDetail, $bid, $flag])
                <div class="surface overflow-hidden flex flex-col">
                    <div class="aspect-[16/10] bg-ink-100">
                        <img src="https://images.unsplash.com/photo-{{ $photo }}?w=640&q=80" alt=""
                             width="640" height="400" loading="lazy" decoding="async"
                             class="w-full h-full object-cover">
                    </div>
                    <div class="p-5 flex flex-col flex-1">
                        <div class="flex items-center gap-2">
                            <span class="bg-ink-950 text-paper px-2 py-0.5 rounded-[4px] text-[10px] font-bold uppercase tracking-[0.1em]">
                                {{ $kind }}
                            </span>
                            <span class="text-[10px] font-bold uppercase tracking-[0.1em] text-brick-600">Live</span>
                        </div>
                        <h3 class="mt-2.5 text-lg font-bold tracking-[-0.015em] text-ink-900">{{ $title }}</h3>
                        <p class="mt-1 text-xs text-ink-500">{!! $sub !!}</p>

                        <dl class="mt-4 grid grid-cols-2 gap-px overflow-hidden rounded-[6px] border border-ink-200 bg-ink-200">
                            @foreach ($facts as [$k, $v, $cls])
                                <div class="bg-paper px-2.5 py-2">
                                    <dt class="text-[10px] uppercase tracking-[0.09em] text-ink-400">{{ $k }}</dt>
                                    <dd class="mt-0.5 text-[13px] font-semibold text-ink-900 {{ $cls }}">{{ $v }}</dd>
                                </div>
                            @endforeach
                        </dl>

                        <div class="mt-auto pt-4 border-t border-ink-200">
                            <p class="text-[10px] font-bold uppercase tracking-[0.1em] text-ink-500">Freight to Tema</p>
                            <p class="mt-1 text-2xl font-bold tracking-[-0.02em] text-ink-900 tnum">
                                {{ $freight }}
                                <span class="text-sm font-semibold text-ink-500">USD</span>
                            </p>
                            <p class="mt-0.5 text-xs text-ink-500">{!! $freightDetail !!}</p>
                            <div class="mt-3 flex gap-2">
                                <button class="flex-1 btn btn-secondary !px-3 !py-2 !text-xs">Collect it myself</button>
                                <button class="flex-1 btn btn-ghost !px-3 !py-2 !text-xs">Collect it for me</button>
                            </div>
                        </div>

                        <div class="mt-4 pt-4 border-t border-ink-200 flex items-end justify-between gap-3">
                            <p class="text-xs text-ink-500">Current bid
                                <span class="block text-lg font-bold text-ink-900 tnum">{{ $bid }}</span>
                            </p>
                            <button class="btn btn-primary !px-4 !py-2 !text-sm">Sign in to bid</button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-8 border-l-2 border-brick-500 pl-4 max-w-3xl">
            <p class="text-sm text-ink-700">
                <strong>The chair card is the finding, not a styling choice.</strong>
                A $60 chair does not cost $357 to move. At <span class="tnum">5.2 kg</span> the freight
                is $1.66 and the forwarder's $250 minimum consignment charge is doing all the rest.
                One chair cannot economically cross the Atlantic on its own.
            </p>
            <p class="mt-2 text-sm text-ink-700">
                So garage items need a rule. Either they are <strong>bundled</strong> — "40 pieces
                from one garage, $400 the lot" — or anything under some weight is
                <strong>collection only</strong> and quotes no freight at all. Listing them singly at
                $257 is the third option, and it is how a buyer loses trust on their first garage
                purchase.
            </p>
        </div>
    </div>

</section>
@endsection