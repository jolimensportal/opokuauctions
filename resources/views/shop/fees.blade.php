@extends('layouts.shop')

@section('title', 'Fees — Opoku Auctions')

@section('content')
<section class="mx-auto max-w-4xl px-4 sm:px-6 pt-12 pb-20">
    <h1 class="text-4xl font-extrabold tracking-[-0.02em] leading-[1.05] text-ink-900">
        Every fee, before you bid
    </h1>
    <p class="mt-4 text-lg text-ink-600 max-w-[58ch]">
        The only charge we cannot publish a number for is government duty, and that
        depends on the vehicle. We show you the receipts for it.
    </p>

    <div class="mt-12 surface overflow-hidden">
        <table class="w-full text-sm">
            <caption class="sr-only">Opoku Auctions fee schedule</caption>
            <thead>
                <tr class="text-left text-xs uppercase tracking-[0.14em] text-ink-500 bg-ink-50 border-b border-ink-200">
                    <th scope="col" class="px-5 py-3 font-semibold">Charge</th>
                    <th scope="col" class="px-5 py-3 font-semibold text-right">Amount</th>
                    <th scope="col" class="px-5 py-3 font-semibold text-right">When it is due</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-200">
                @foreach ([
                    ['Deposit', '20% of hammer', 'When the auction closes'],
                    ['Shared container freight', 'USD 1,400', 'Before loading'],
                    ['Dedicated container freight', 'USD 3,200', 'Before loading'],
                    ['Handling', 'USD 350', 'Before loading'],
                    ['Tema clearing, duty, VAT, port', 'Actual cost, receipted', 'At Tema'],
                ] as [$charge, $amount, $when])
                    <tr>
                        <th scope="row" class="px-5 py-4 text-left font-medium text-ink-900">{{ $charge }}</th>
                        <td class="px-5 py-4 text-right tnum text-ink-800">{{ $amount }}</td>
                        <td class="px-5 py-4 text-right text-ink-500">{{ $when }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="mt-6 text-sm text-ink-500">
        Rates shown in USD. You are quoted in GHS or CAD at the rate fixed when you pay
        your deposit, so a later exchange-rate move cannot change what you owe.
    </p>
</section>
@endsection
