@extends('layouts.shop')

@section('title', 'Live lots — Opoku Auctions')

@section('content')
<section class="mx-auto max-w-6xl px-4 sm:px-6 pt-10 pb-20">

    <h1 class="text-3xl font-extrabold tracking-tight text-ink-900">Live lots</h1>
    <p class="mt-2 text-ink-600 max-w-[56ch]">
        {{ $lots->total() }} vehicle{{ $lots->total() === 1 ? '' : 's' }} available.
        Every price shown is the hammer price. The landed total is on each lot.
    </p>

    {{-- Filters. Labels sit above inputs, placeholders never stand in for labels,
         and every filter is optional so browsing never requires a decision. --}}
    <form method="GET" action="{{ route('lots.index') }}" class="mt-8 grid gap-4 sm:grid-cols-4">
        <div>
            <label class="label" for="city">City</label>
            <select name="city" id="city" class="field">
                <option value="">Any city</option>
                @foreach ($cities as $c)
                    <option value="{{ $c }}" @selected(($filters['city'] ?? '') === $c)>{{ $c }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="make">Make</label>
            <select name="make" id="make" class="field">
                <option value="">Any make</option>
                @foreach ($makes as $m)
                    <option value="{{ $m }}" @selected(($filters['make'] ?? '') === $m)>{{ $m }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="condition">Condition</label>
            <select name="condition" id="condition" class="field">
                <option value="">Any condition</option>
                @foreach (['clean_title', 'salvage', 'project', 'parts_only'] as $c)
                    <option value="{{ $c }}" @selected(($filters['condition'] ?? '') === $c)>{{ str_replace('_', ' ', $c) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end">
            <button type="submit" class="btn btn-secondary w-full">Apply</button>
        </div>
    </form>

    <div class="mt-10">
    @include('shop.partials.lot-board-header')

        @forelse ($lots as $lot)
            @include('shop.partials.lot-row', ['lot' => $lot])
        @empty
            {{-- Empty state says what to do next, not just that there is nothing. --}}
            <div class="py-20 text-center">
                <p class="text-ink-600">No lots match those filters.</p>
                <a href="{{ route('lots.index') }}" class="btn btn-ghost mt-5">Clear filters</a>
            </div>
        @endforelse
    </div>

    <div class="mt-12">{{ $lots->links() }}</div>
</section>
@endsection
