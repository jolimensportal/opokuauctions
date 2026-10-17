@extends('layouts.shop')

@section('title', 'My account — Opoku Auctions')

@section('content')
<section class="mx-auto max-w-4xl px-4 sm:px-6 pt-12 pb-20">

    @if (session('status'))
        <div class="mb-8 bg-brick-50 border border-brick-200 text-brick-900 text-sm px-4 py-3 rounded-[8px]" role="status">
            {{ session('status') }}
        </div>
    @endif

    <h1 class="text-3xl font-extrabold tracking-tight text-ink-900">
        Hello, {{ Str::before(auth()->user()->name, ' ') }}
    </h1>

    <div class="mt-10">
        <h2 class="text-xl font-extrabold tracking-tight text-ink-900">Your lots</h2>

        @forelse ($wonLots as $lot)
            <div class="flex items-center justify-between gap-4 py-4 border-b border-ink-200">
                <div class="min-w-0">
                    <a href="{{ route('lots.show', $lot) }}"
                       class="font-semibold text-ink-900 hover:text-brick-600">
                        {{ $lot->title }}
                    </a>
                    <p class="text-xs text-ink-500 tnum">
                        {{ str_replace('_', ' ', $lot->status) }}
                        @if ($lot->ends_at) &middot; closed {{ $lot->ends_at->format('j M Y') }} @endif
                    </p>
                </div>
                <span class="tnum font-bold text-ink-900 shrink-0">{{ number_format($lot->current_bid / 100, 0) }}</span>
            </div>
        @empty
            <p class="mt-4 text-ink-600">
                You have not won a lot yet.
                <a href="{{ route('lots.index') }}" class="font-semibold text-brick-600 hover:text-brick-700">See what is live</a>.
            </p>
        @endforelse
    </div>

    @if ($shipments->isNotEmpty())
        <div class="mt-14">
            <h2 class="text-xl font-extrabold tracking-tight text-ink-900">In transit</h2>

            @foreach ($shipments as $shipment)
                <a href="{{ route('orders.show', $shipment) }}"
                   class="mt-4 flex items-center justify-between gap-4 surface p-5 hover:border-ink-400 transition-colors">
                    <div>
                        <div class="tnum font-semibold text-ink-900">{{ $shipment->container_no ?? 'Container pending' }}</div>
                        <p class="text-sm text-ink-600">{{ ucfirst(str_replace('_', ' ', $shipment->status)) }}</p>
                    </div>
                    <span class="text-sm font-semibold text-brick-600">Track</span>
                </a>
            @endforeach
        </div>
    @endif
</section>
@endsection
