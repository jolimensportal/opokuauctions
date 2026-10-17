@extends('layouts.shop')

@section('title', 'Shipment — Opoku Auctions')

@section('content')
<section class="mx-auto max-w-4xl px-4 sm:px-6 pt-12 pb-20">
    <a href="{{ route('orders.index') }}" class="text-sm text-ink-500 hover:text-ink-900">&larr; My purchases</a>

    <div class="mt-6 flex flex-wrap items-baseline gap-x-4 gap-y-1">
        <h1 class="text-3xl font-extrabold tracking-tight tnum text-ink-900">
            {{ $shipment->container_no ?? 'Container pending' }}
        </h1>
        <span class="text-sm font-semibold text-brick-600">{{ ucfirst(str_replace('_', ' ', $shipment->status)) }}</span>
    </div>
    <p class="mt-1 text-ink-600">{{ $shipment->seaport_origin ?? 'North America' }} &rarr; {{ $shipment->seaport_destination ?? 'Tema' }}</p>

    {{-- Milestones as a vertical timeline: a sequence of events with real
         timestamps reads as a journey, which is exactly what it is. --}}
    <div class="mt-12">
        <h2 class="text-xl font-extrabold tracking-tight text-ink-900">Progress</h2>

        @foreach ($shipment->milestones as $milestone)
            <div class="grid gap-1 sm:grid-cols-12 gap-y-1 py-4 border-t border-ink-200">
                <span class="sm:col-span-3 text-sm tnum text-ink-500">
                    {{ $milestone->occurred_at?->format('j M Y, H:i') }}
                </span>
                <span class="sm:col-span-9">
                    <span class="text-sm font-semibold text-ink-900">{{ Str::headline($milestone->type) }}</span>
                    @if ($milestone->notes)
                        <span class="block text-sm text-ink-600">{{ $milestone->notes }}</span>
                    @endif
                </span>
            </div>
        @endforeach
    </div>

    <div class="mt-12">
        <h2 class="text-xl font-extrabold tracking-tight text-ink-900">Vehicles in this container</h2>

        @foreach ($shipment->lots as $shipmentLot)
            @php $lot = $shipmentLot->lot; @endphp
            @if ($lot)
                <a href="{{ route('lots.show', $lot) }}" class="flex items-center justify-between gap-4 py-3 border-b border-ink-200">
                    <span class="font-medium text-ink-900">{{ $lot->title }}</span>
                    <span class="text-xs tnum text-ink-500">{{ $lot->vehicleDetail?->vin }}</span>
                </a>
            @endif
        @endforeach
    </div>
</section>
@endsection
