{{-- CANDIDATE 1b — "The board"
     Dense, market-board feel: the hero IS the live inventory. Opens on the
     lots because that is what a buyer came for, and the trust material sits
     below the fold rather than above it.

     Same palette and type as 1a. Only the hierarchy and density differ, which
     is what makes them worth comparing side by side. --}}
@extends('layouts.shop')

@section('title', 'Opoku Auctions — live North America lots for Ghana')

@section('content')

{{-- Tighter top padding than 1a (pt-10 vs pt-16). The board wants to be seen
     immediately. --}}
<section class="mx-auto max-w-6xl px-4 sm:px-6 pt-10 pb-10">
    <div class="grid gap-10 lg:grid-cols-12 lg:items-end">
        <div class="lg:col-span-7">
            <h1 class="font-extrabold tracking-[-0.03em] leading-[0.95] text-ink-900 text-[2.5rem] sm:text-5xl">
                North America, by the container load
            </h1>
            <p class="mt-5 text-lg text-ink-600 max-w-[48ch]">
                Salvage and clean-title lots from Detroit, New York and Milwaukee.
                Cleared at Tema. You bid in your own currency.
            </p>
            <div class="mt-7 flex flex-wrap items-center gap-3">
                <a href="{{ route('lots.index') }}" class="btn btn-primary">Browse the board</a>
                <a href="{{ route('fees') }}" class="btn btn-ghost">See all fees</a>
            </div>
        </div>

        {{-- Counters instead of a photograph. On this candidate the numbers
             ARE the hero, which suits a board people use to check listings. --}}
        <div class="lg:col-span-5">
            <dl class="grid grid-cols-3 gap-px bg-ink-200 rounded-[10px] overflow-hidden border border-ink-200">
                @foreach ([
                    ['Live now', (string) ($liveCount ?? 0)],
                    ['In transit', (string) ($transitCount ?? 0)],
                    ['Cleared', (string) ($clearedCount ?? 0)],
                ] as $i => [$label, $value])
                    <div class="bg-paper px-4 py-5">
                        <dd class="text-2xl font-extrabold tnum text-ink-900">{{ $value }}</dd>
                        <dt class="mt-1 text-xs uppercase tracking-[0.14em] text-ink-500">{{ $label }}</dt>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>
</section>

{{-- THE BOARD. Compact rows: photo, identity, condition, city, price, clock.
     Higher density than 1a by design — a board is for scanning. --}}
<section class="mx-auto max-w-6xl px-4 sm:px-6 pb-20">
    <div class="flex items-end justify-between gap-6 pb-4 border-b border-ink-300">
        <h2 class="text-xl font-extrabold tracking-tight text-ink-900">Live board</h2>
        <a href="{{ route('lots.index') }}" class="text-sm font-semibold text-brick-600 hover:text-brick-700">Filter and sort</a>
    </div>

    <div class="hidden sm:grid grid-cols-12 gap-4 pt-3 text-xs uppercase tracking-[0.14em] text-ink-400">
        <span class="col-span-2"></span>
        <span class="col-span-4">Vehicle</span>
        <span class="col-span-2">Location</span>
        <span class="col-span-2">Condition</span>
        <span class="col-span-1">Closes</span>
        <span class="col-span-1 text-right">Bid</span>
    </div>

    @forelse ($featuredLots ?? [] as $lot)
        <a href="{{ route('lots.show', $lot) }}" class="reveal grid grid-cols-12 gap-4 items-center py-3 border-b border-ink-200 hover:bg-ink-50/70 transition-colors">
            {{-- The thumbnail is sized so the vehicle is recognisable. At the
                 56px it started at, every car was an unidentifiable sliver and
                 the row carried a photo without carrying information. --}}
            <div class="col-span-4 sm:col-span-2">
                <img src="{{ $lot->primaryPhoto() ?? 'https://picsum.photos/seed/opoku-lot-'.$lot->id.'/400/260' }}"
                     alt="{{ $lot->year }} {{ $lot->make }} {{ $lot->model }}"
                     width="400" height="260" loading="lazy" decoding="async"
                     class="w-full aspect-[3/2] object-cover rounded-[8px] border border-ink-200">
            </div>

            <div class="col-span-8 sm:col-span-4 min-w-0">
                <div class="font-semibold text-ink-900 truncate">{{ $lot->year }} {{ $lot->make }} {{ $lot->model }}</div>
                <div class="text-xs text-ink-500 tnum truncate">{{ $lot->vin }}</div>
            </div>

            <div class="hidden sm:block col-span-2 text-sm text-ink-600 truncate">{{ $lot->city }}</div>

            <div class="hidden sm:block col-span-2 text-sm">
                <span class="text-xs uppercase tracking-wide text-ink-500">{{ str_replace('_', ' ', $lot->condition) }}</span>
            </div>

            {{-- Time left is the most decision-relevant column on a live board.
                 Leaving it out made this read as an inventory list. --}}
            <div class="hidden sm:block col-span-1 text-sm tnum {{ $lot->isAcceptingBids() ? 'text-brick-600 font-semibold' : 'text-ink-400' }}">
                {{ $lot->timeUntilClose() }}
            </div>

            <div class="col-span-12 sm:col-span-1 text-right">
                <span class="tnum font-bold text-ink-900">{{ number_format($lot->current_bid / 100) }}</span>
                <span class="text-xs text-ink-500 ml-1">{{ strtoupper($lot->currency_code) }}</span>
            </div>
        </a>
    @empty
        <div class="py-16 text-center">
            <p class="text-ink-600">No lots are live right now.</p>
            <p class="mt-1 text-sm text-ink-500">New inventory is posted on Sundays.</p>
        </div>
    @endforelse
</section>

{{-- TRUST, AS A FEE TABLE. On this candidate the trust argument is a table of
     published numbers, which is the most checkable form of trust available. --}}
<section class="border-y border-ink-200 bg-ink-50/60">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-16 grid gap-10 lg:grid-cols-12">
        <div class="lg:col-span-4">
            <h2 class="text-2xl font-extrabold tracking-tight text-ink-900">
                Every charge, published
            </h2>
            <p class="mt-3 text-ink-600 max-w-[38ch]">
                No percentage we do not disclose, no handling fee that appears after
                you have bid.
            </p>
        </div>

        <div class="lg:col-span-8">
            <table class="w-full text-sm">
                <caption class="sr-only">Opoku Auctions fee schedule</caption>
                <thead>
                    <tr class="text-left text-xs uppercase tracking-[0.14em] text-ink-500 border-b border-ink-300">
                        <th scope="col" class="py-2.5 font-semibold">Charge</th>
                        <th scope="col" class="py-2.5 font-semibold text-right">Amount</th>
                        <th scope="col" class="py-2.5 font-semibold text-right">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-200">
                    @foreach ([
                        ['Deposit', '20% of hammer', 'At auction close'],
                        ['Shared container freight', 'USD 1,400', 'Before loading'],
                        ['Dedicated container freight', 'USD 3,200', 'Before loading'],
                        ['Duty, VAT, port charges', 'Actual, receipted', 'At Tema'],
                        ['Our handling', 'USD 350', 'Before loading'],
                    ] as [$charge, $amount, $when])
                        <tr>
                            <th scope="row" class="py-3 text-left font-medium text-ink-900">{{ $charge }}</th>
                            <td class="py-3 text-right tnum text-ink-800">{{ $amount }}</td>
                            <td class="py-3 text-right text-ink-500">{{ $when }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>

{{-- CLOSING CTA. One intent, one label — the same "See live lots" as the
     hero, not a second phrasing of it. --}}
<section class="mx-auto max-w-6xl px-4 sm:px-6 py-20">
    <div class="surface px-6 sm:px-10 py-14 text-center">
        <h2 class="text-3xl font-extrabold tracking-tight text-ink-900 max-w-[20ch] mx-auto">
            The next lot closes on Sunday
        </h2>
        <p class="mt-3 text-ink-600 max-w-[46ch] mx-auto">
            Create an account, fund your wallet by card or Mobile Money, and you can bid
            the same evening.
        </p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ route('register') }}" class="btn btn-primary">Create account</a>
        </div>
    </div>
</section>

@endsection