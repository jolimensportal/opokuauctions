@extends('layouts.shop')

@section('title', 'My account — Opoku Auctions')

@section('content')
{{--
    One account, both businesses.

    Buying and shipping sit side by side rather than behind tabs. A tab makes
    the larger of the two look like an afterthought, and shipping is the larger
    of the two — so it gets equal weight on the first screen rather than one
    click away.

    The shipping column ships an empty state because there is no sender concept
    yet. It reads as an invitation, not as a panel that failed to load: a fake
    parcel on someone's real dashboard would be worse than no column at all.
--}}
<section class="mx-auto max-w-6xl px-4 sm:px-6 pt-10 pb-20">

    @if (session('status'))
        <div class="mb-6 bg-brick-50 border border-brick-200 text-brick-900 text-sm px-4 py-3 rounded-[8px]" role="status">
            {{ session('status') }}
        </div>
    @endif

    <h1 class="text-3xl font-extrabold tracking-tight text-ink-900">
        Hello, {{ Str::before(auth()->user()->name, ' ') }}
    </h1>

    {{-- The one thing worth acting on today, stated plainly. --}}
    <div class="mt-6 rounded-[14px] border border-brick-200 bg-brick-50 px-5 py-4">
        <p class="text-[0.9375rem] font-bold text-brick-600">Everything you have with us, in one place.</p>
        <p class="mt-1 text-sm text-brick-700">
            You are winning
            <a href="#auctions" class="font-semibold underline underline-offset-2">{{ count($wonLots) }} {{ Str::plural('lot', count($wonLots)) }}</a>
            and have
            <a href="#shipping" class="font-semibold underline underline-offset-2">{{ count($shipments) }} {{ Str::plural('shipment', count($shipments)) }}</a>
            on the water.
        </p>
    </div>

    {{-- Icon-free tiles. At this density the number is the content. --}}
    <div class="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-[14px] border border-ink-200 bg-ink-200 sm:grid-cols-4">
        @foreach ([
            ['Won this month', $tiles['wonThisMonth'], null],
            ['In transit', $tiles['inTransit'], 'to Tema'],
            ['Parcels sent', $tiles['parcelsSent'], 'this year'],
            ['Balance', '$'.number_format($tiles['balanceCents'] / 100, 2), 'settled'],
        ] as [$label, $value, $hint])
            <div class="bg-white px-4 py-3">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-ink-500">{{ $label }}</p>
                <p class="tnum mt-1 text-2xl font-extrabold tracking-tight text-ink-900">{{ $value }}</p>
                @if ($hint)
                    <p class="mt-0.5 text-[11px] font-medium text-ink-500">{{ $hint }}</p>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-5 lg:grid-cols-2">

        {{-- ------------------------------------------------------ Buying --}}
        <section id="auctions" class="overflow-hidden rounded-[14px] border border-ink-200 bg-white">
            <header class="flex items-center gap-2.5 border-b border-ink-200 px-4 py-3">
                <h2 class="text-[0.9375rem] font-bold tracking-tight text-ink-900">Buying</h2>
                <span class="rounded-full bg-brick-50 px-2 py-0.5 text-[10px] font-bold text-brick-600">Your auctions</span>
                <a href="{{ route('lots.index') }}" class="ml-auto text-xs font-semibold text-brick-600 hover:underline">Browse lots</a>
            </header>

            @forelse ($wonLots as $lot)
                <a href="{{ route('lots.show', $lot) }}" class="flex items-center gap-3 border-b border-ink-100 px-4 py-3 transition-colors last:border-0 hover:bg-ink-50">
                    <span class="grid h-10 w-10 flex-none place-items-center rounded-[9px] bg-brick-50 text-[10px] font-bold text-brick-600">
                        {{ Str::upper(Str::substr($lot->title, 0, 3)) }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-ink-900">{{ $lot->title }}</span>
                        <span class="tnum mt-0.5 block text-[11px] text-ink-500">
                            {{ $lot->city }} &middot; closes {{ $lot->ends_at?->format('j M, H:i') }}
                        </span>
                    </span>
                    <span class="tnum flex-none text-sm font-bold text-ink-900">
                        {{ number_format($lot->current_bid / 100, 2) }}
                    </span>
                </a>
            @empty
                <div class="px-4 py-10 text-center">
                    <p class="text-sm font-semibold text-ink-900">No lots yet</p>
                    <p class="mt-1 text-[13px] text-ink-600">
                        You are not the highest bidder on anything right now.
                        <a href="{{ route('lots.index') }}" class="font-semibold text-brick-600 hover:underline">See what is closing</a>.
                    </p>
                </div>
            @endforelse
        </section>

        {{-- ---------------------------------------------------- Shipping --}}
        <section id="shipping" class="overflow-hidden rounded-[14px] border border-ink-200 bg-white">
            <header class="flex items-center gap-2.5 border-b border-ink-200 px-4 py-3">
                <h2 class="text-[0.9375rem] font-bold tracking-tight text-ink-900">Shipping</h2>
                <span class="rounded-full bg-ink-100 px-2 py-0.5 text-[10px] font-bold text-ink-700">Your parcels</span>
                <a href="{{ route('send.create') }}" class="ml-auto text-xs font-semibold text-brick-600 hover:underline">Get a price</a>
            </header>

            @forelse ($shipments as $shipment)
                <div class="flex items-center gap-3 border-b border-ink-100 px-4 py-3 last:border-0">
                    <span class="grid h-10 w-10 flex-none place-items-center rounded-[9px] bg-ink-100 text-[10px] font-bold text-ink-700">
                        {{ Str::upper(Str::substr($shipment->container_no ?? $shipment->id, 0, 3)) }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-ink-900">
                            Container {{ $shipment->container_no ?? $shipment->id }}
                        </span>
                        <span class="tnum mt-0.5 block text-[11px] text-ink-500">
                            {{ str_replace('_', ' ', $shipment->status) }} &middot; to {{ $shipment->seaport_destination }}
                        </span>
                    </span>
                    <a href="{{ route('orders.show', $shipment) }}" class="text-xs font-semibold text-brick-600 hover:underline">Track</a>
                </div>
            @empty
                {{-- Honest empty state. There is no sender concept yet, so
                     every customer sees this. It reads as an invitation because
                     it is one — the rate card behind it is already built. --}}
                <div class="px-4 py-10 text-center">
                    <p class="text-sm font-semibold text-ink-900">Nothing in transit</p>
                    <p class="mx-auto mt-1 max-w-[34ch] text-[13px] text-ink-600">
                        Send something to Tema from anywhere in the USA or Canada, whether or
                        not you bought it from us. Priced by weight and size, and you see the
                        all-in figure before you pay.
                    </p>
                    <a href="{{ route('send.create') }}"
                       class="mt-4 inline-flex items-center rounded-full bg-brick-500 px-4 py-2 text-xs font-bold text-paper transition-colors hover:bg-brick-600">
                        Get my price
                    </a>
                </div>
            @endforelse
        </section>
    </div>
</section>
@endsection