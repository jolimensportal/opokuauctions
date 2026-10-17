@extends('layouts.shop')

@section('title', 'Wallet — Opoku Auctions')

@section('content')
<section class="mx-auto max-w-4xl px-4 sm:px-6 pt-12 pb-20">

    @if (session('status'))
        <div class="mb-8 bg-brick-50 border border-brick-200 text-brick-900 text-sm px-4 py-3 rounded-[8px]" role="status">
            {{ session('status') }}
        </div>
    @endif

    <h1 class="text-3xl font-extrabold tracking-tight text-ink-900">Wallet</h1>

    <div class="mt-8 grid gap-5 sm:grid-cols-3">
        <div class="surface p-6 sm:col-span-1">
            <div class="text-xs uppercase tracking-[0.14em] text-ink-500">Available</div>
            <div class="mt-2 text-3xl font-extrabold tnum text-ink-900">
                {{ number_format($wallet->balance_available / 100, 2) }}
            </div>
            <div class="text-xs text-ink-500 tnum">USD</div>
        </div>
        <div class="surface p-6">
            <div class="text-xs uppercase tracking-[0.14em] text-ink-500">Held</div>
            <div class="mt-2 text-3xl font-extrabold tnum text-ink-900">
                {{ number_format($wallet->balance_held / 100, 2) }}
            </div>
            <p class="mt-1 text-xs text-ink-500">Reserved against a lot you won.</p>
        </div>
        <div class="surface p-6">
            <div class="text-xs uppercase tracking-[0.14em] text-ink-500">Ledger</div>
            @if ($reconciliation['ok'] ?? false)
                <div class="mt-2 text-lg font-bold text-ink-900">Balanced</div>
                <p class="mt-1 text-xs text-ink-500">Matches the entry log.</p>
            @else
                <div class="mt-2 text-lg font-bold text-brick-700">Needs review</div>
                <p class="mt-1 text-xs text-ink-500">Our team has been notified.</p>
            @endif
        </div>
    </div>

    {{-- Funding. One currency selector, because offering three providers at once
         would make the choice look like a decision we are passing to the buyer. --}}
    <div class="mt-10 surface p-6">
        <h2 class="text-xl font-extrabold tracking-tight text-ink-900">Add money</h2>
        <p class="mt-1.5 text-sm text-ink-600">Card and Mobile Money. Funds sit in your balance until you spend them.</p>

        <form method="POST" action="{{ route('wallet.deposit') }}" class="mt-6 grid gap-4 sm:grid-cols-4 sm:items-end">
            @csrf
            <div class="sm:col-span-1">
                <label class="label" for="currency">Paying in</label>
                <select id="currency" name="currency" class="field">
                    <option value="ghs">GHS</option>
                    <option value="cad">CAD</option>
                    <option value="usd">USD</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <label class="label" for="amount">Amount</label>
                <input id="amount" name="amount" type="number" step="0.01" min="10" max="50000" required class="field tnum">
            </div>
            <div class="sm:col-span-1">
                <button type="submit" class="btn btn-primary w-full">Continue</button>
            </div>
        </form>
    </div>

    {{-- Ledger. The last twenty entries, newest first. A buyer who cannot see
         where their money went will not add more of it. --}}
    <div class="mt-10">
        <h2 class="text-xl font-extrabold tracking-tight text-ink-900">Recent activity</h2>

        @forelse ($entries as $entry)
            <div class="flex items-baseline justify-between gap-4 py-3 border-b border-ink-200">
                <div class="min-w-0">
                    <div class="text-sm font-medium text-ink-900">{{ $entry->note ?? Str::headline($entry->type) }}</div>
                    <div class="text-xs text-ink-400 tnum">{{ $entry->created_at->format('j M Y, H:i') }}</div>
                </div>
                <div class="tnum text-sm font-semibold shrink-0 {{ $entry->amount >= 0 ? 'text-ink-900' : 'text-brick-600' }}">
                    {{ $entry->amount >= 0 ? '+' : '' }}{{ number_format($entry->amount / 100, 2) }}
                </div>
            </div>
        @empty
            <p class="mt-4 text-ink-600">Nothing yet. Fund your wallet to start bidding.</p>
        @endforelse
    </div>
</section>
@endsection
