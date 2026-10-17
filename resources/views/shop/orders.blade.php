@extends('layouts.shop')

@section('title', 'My purchases — Opoku Auctions')

@section('content')
<section class="mx-auto max-w-4xl px-4 sm:px-6 pt-12 pb-20">
    <h1 class="text-3xl font-extrabold tracking-tight text-ink-900">My purchases</h1>
    <p class="mt-2 text-ink-600">Every container you have a vehicle in.</p>

    <div class="mt-10">
        @forelse ($shipments as $shipment)
            <a href="{{ route('orders.show', $shipment) }}"
               class="grid gap-3 sm:grid-cols-12 items-center py-5 border-b border-ink-200 hover:bg-ink-50/70 transition-colors">
                <div class="sm:col-span-5">
                    <div class="tnum font-semibold text-ink-900">{{ $shipment->container_no ?? 'Container pending' }}</div>
                    <div class="text-xs text-ink-500">
                        {{ $shipment->lots->count() }} vehicle{{ $shipment->lots->count() === 1 ? '' : 's' }}
                    </div>
                </div>
                <div class="sm:col-span-4 text-sm text-ink-600">
                    {{ $shipment->seaport_origin ?? 'North America' }} &rarr; {{ $shipment->seaport_destination ?? 'Tema' }}
                </div>
                <div class="sm:col-span-3 text-right">
                    <span class="text-sm font-semibold text-ink-900">{{ ucfirst(str_replace('_', ' ', $shipment->status)) }}</span>
                </div>
            </a>
        @empty
            <p class="text-ink-600">Nothing in transit yet.</p>
        @endforelse
    </div>
</section>
@endsection
