@extends('layouts.shop')

@section('title', 'How it works — Opoku Auctions')

@section('content')
<section class="mx-auto max-w-3xl px-4 sm:px-6 pt-12 pb-20">
    <h1 class="text-4xl font-extrabold tracking-[-0.02em] leading-[1.05] text-ink-900">
        How buying from Opoku works
    </h1>
    <p class="mt-4 text-lg text-ink-600">
        Five steps. The two that surprise people are the deposit and the balance, so
        those get the most detail.
    </p>

    <ol class="mt-12">
        @foreach ([
            ['Create an account', 'Name, email and a phone number we can reach. That is all we ask for before you bid.'],
            ['Fund your wallet', 'Add money by card or Mobile Money. It sits in your balance until you spend it, and you can withdraw it if you change your mind.'],
            ['Bid on a lot', 'You will see the hammer price, the deposit due and what the vehicle will cost landed before you commit.'],
            ['Pay the deposit', 'When a lot closes in your favour, twenty percent is taken and held against that specific vehicle.'],
            ['Pay the balance, we ship', 'Freight, clearing and our handling fee are due before the vehicle loads. Once the balance is settled, the container leaves.'],
            ['Clear and collect at Tema', 'We handle duty, VAT and port charges, showing you every receipt. We message you the day it clears.'],
        ] as $i => [$heading, $body])
            <li class="grid gap-2 sm:grid-cols-12 gap-y-2 py-7 border-t border-ink-200">
                <span class="sm:col-span-1 text-sm tnum text-brick-500 font-semibold">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span>
                <h2 class="sm:col-span-4 text-lg font-bold tracking-tight text-ink-900">{{ $heading }}</h2>
                <p class="sm:col-span-7 text-ink-600">{{ $body }}</p>
            </li>
        @endforeach
    </ol>

    {{-- The question buyers actually ask, answered before they ask it. --}}
    <div class="mt-16 surface p-6 sm:p-8">
        <h2 class="text-2xl font-extrabold tracking-tight text-ink-900">
            What happens if something goes wrong?
        </h2>
        <p class="mt-3 text-ink-600">
            If a vehicle arrives not as described, tell us before you accept it. We
            photograph it, we open a claim with the forwarder, and your deposit is not
            released until the claim is settled. Only an Owner can release a disputed
            deposit, so no single operator can quietly move your money.
        </p>
        <a href="{{ route('fees') }}" class="btn btn-ghost mt-6">See the full fee table</a>
    </div>
</section>
@endsection
