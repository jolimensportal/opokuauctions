{{-- Buyer-facing shell, built to the depth a vehicle-export marketplace runs at:
     an offer strip, a two-row header with the currency switch, and a footer with
     real link columns rather than three decorative ones. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Opoku Auctions — affordable quality auto-auctions, North American garage to Tema Port')</title>
    <meta name="description" content="@yield('description', 'Affordable quality auto-auctions. Salvage and clean-title vehicles from North American garage sales, cleared and delivered at Tema Port.')">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-[100dvh] flex flex-col">

{{-- OFFER STRIP. The first thing a buyer from the USA or Ghana sees. States a
     concrete commercial fact, never a countdown nobody verified. --}}
<div class="bg-ink-950 text-ink-200">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 h-9 flex items-center justify-between gap-4 text-xs">
        <p class="truncate">
            <span class="text-brick-400 font-semibold">Ghana buyers:</span>
            prices shown in {{ strtoupper($currency->current()) }} &middot; you pay in USD, we clear at Tema
        </p>
        <a href="{{ route('fees') }}" class="hidden sm:inline whitespace-nowrap hover:text-paper">See every charge &rarr;</a>
    </div>
</div>

<header class="sticky top-0 z-40 bg-paper/95 backdrop-blur border-b border-ink-200">
    <div class="mx-auto max-w-7xl px-4 sm:px-6">
        {{-- Row 1: identity, currency switch, account. --}}
        <div class="h-16 flex items-center gap-5">
            <a href="{{ route('home') }}" class="flex items-baseline gap-1.5 shrink-0">
                <span class="font-bold tracking-tight text-[1.125rem] text-ink-900">Opoku</span>
                <span class="font-bold tracking-tight text-[1.125rem] text-brick-600">Auctions</span>
            </a>

            <nav class="hidden lg:flex items-center gap-7 text-sm text-ink-700 ml-3" aria-label="Primary">
                <a href="{{ route('lots.index') }}" class="hover:text-ink-950">Find cars</a>
                <a href="{{ route('how-it-works') }}" class="hover:text-ink-950">How to buy</a>
                <a href="{{ route('fees') }}" class="hover:text-ink-950">Fees</a>
                <a href="{{ route('orders.index') }}" class="hover:text-ink-950">Track my car</a>
                <a href="{{ route('home') }}#support" class="hover:text-ink-950">Support</a>
            </nav>

            <div class="ml-auto flex items-center gap-2 sm:gap-3">
                {{-- Currency switch. A real form POST so it cannot be triggered
                     by a prefetching crawler on the wrong page. --}}
                <form method="POST" action="{{ route('currency.update') }}" class="hidden sm:flex items-center">
                    @csrf
                    <div class="flex items-center border border-ink-300 rounded-pill overflow-hidden">
                        @foreach ($currency->options() as $option)
                            <button type="submit" name="currency" value="{{ $option }}"
                                    class="px-2.5 py-1 text-xs font-semibold tnum transition-colors
                                           {{ $currency->current() === $option
                                              ? 'bg-ink-900 text-paper'
                                              : 'bg-paper text-ink-600 hover:bg-ink-100' }}">
                                {{ strtoupper($option) }}
                            </button>
                        @endforeach
                    </div>
                </form>

                @auth
                    <a href="{{ route('wallet') }}" class="hidden md:inline-flex btn btn-ghost tnum text-xs">
                        {{ $currency->format($authWallet = auth()->user()->wallet('usd') ? $authWallet->balance_available : 0) }}
                    </a>
                    <a href="{{ route('dashboard') }}" class="btn btn-ghost text-sm">My account</a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-ghost text-sm">Sign in</a>
                    <a href="{{ route('register') }}" class="btn btn-primary text-sm">
                        <span class="sm:hidden">Join</span><span class="hidden sm:inline">Create account</span>
                    </a>
                @endauth
            </div>
        </div>

        {{-- Row 2: secondary entry points a marketplace needs. Kept visible on
             mobile as a scrollable strip rather than a hidden hamburger, because
             this audience is browsing on a phone. --}}
        <nav class="hidden md:flex items-center gap-6 pb-2.5 text-xs text-ink-500" aria-label="Browse">
            <span class="uppercase tracking-[0.14em]">Browse by</span>
            <a href="{{ route('lots.index', ['condition' => 'clean_title']) }}" class="hover:text-ink-900">Clean title</a>
            <a href="{{ route('lots.index', ['condition' => 'salvage']) }}" class="hover:text-ink-900">Salvage</a>
            <a href="{{ route('lots.index', ['condition' => 'project']) }}" class="hover:text-ink-900">Project cars</a>
            <a href="{{ route('lots.index') }}" class="hover:text-ink-900">All makes</a>
            <span class="h-3 w-px bg-ink-300"></span>
            <a href="{{ route('how-it-works') }}#dispute" class="hover:text-ink-900">Damage &amp; disputes</a>
            <a href="{{ route('how-it-works') }}#payment" class="hover:text-ink-900">How payment works</a>
        </nav>
    </div>
</header>

<main class="flex-1">
    @yield('content')
</main>

<footer class="band-ink mt-20" id="support">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-14">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <div class="flex items-baseline gap-1.5">
                    <span class="font-bold tracking-tight text-paper">Opoku</span>
                    <span class="font-bold tracking-tight text-brick-400">Auctions</span>
                </div>
                <p class="mt-3 text-sm text-ink-300 max-w-[38ch]">
                    Affordable quality auto-auctions. We buy salvage and clean-title
                    vehicles out of North American garage sales, clear them, ship them,
                    and hand them to you at Tema Port.
                </p>
                <div class="mt-5 flex gap-2">
                    <form method="POST" action="{{ route('currency.update') }}" class="sm:hidden">
                        @csrf
                        <input type="hidden" name="currency" value="{{ $currency->current() === 'usd' ? 'ghs' : 'usd' }}">
                        <button type="submit" class="btn btn-ghost-dark text-xs">Show in {{ $currency->current() === 'usd' ? 'GHS' : 'USD' }}</button>
                    </form>
                </div>
            </div>

            <nav aria-label="Buy">
                <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-ink-500">Buy</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a href="{{ route('lots.index') }}" class="text-ink-200 hover:text-paper">All live lots</a></li>
                    <li><a href="{{ route('lots.index', ['condition' => 'clean_title']) }}" class="text-ink-200 hover:text-paper">Clean title</a></li>
                    <li><a href="{{ route('lots.index', ['condition' => 'salvage']) }}" class="text-ink-200 hover:text-paper">Salvage</a></li>
                    <li><a href="{{ route('lots.index', ['condition' => 'project']) }}" class="text-ink-200 hover:text-paper">Project cars</a></li>
                    <li><a href="{{ route('fees') }}" class="text-ink-200 hover:text-paper">Fee schedule</a></li>
                    <li><a href="{{ route('how-it-works') }}" class="text-ink-200 hover:text-paper">How to buy</a></li>
                </ul>
            </nav>

            <nav aria-label="Account">
                <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-ink-500">Account</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a href="{{ route('dashboard') }}" class="text-ink-200 hover:text-paper">My account</a></li>
                    <li><a href="{{ route('wallet') }}" class="text-ink-200 hover:text-paper">Wallet &amp; deposits</a></li>
                    <li><a href="{{ route('orders.index') }}" class="text-ink-200 hover:text-paper">Track my car</a></li>
                    <li><a href="{{ route('register') }}" class="text-ink-200 hover:text-paper">Create account</a></li>
                </ul>
            </nav>

            <div>
                <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-ink-500">Talk to us</h2>
                <p class="mt-3 text-sm text-ink-300">WhatsApp is fastest. English or Twi.</p>
                <p class="mt-2 text-sm tnum text-paper">+233 30 000 0000</p>
                <p class="mt-1 text-xs text-ink-500">Mon&ndash;Sat, 8:00&ndash;18:00 GMT</p>
                <p class="mt-4 text-xs text-ink-400">{{ $currency->rateLabel() }}</p>
            </div>
        </div>

        <hr class="mt-12 border-t border-ink-800">
        <div class="mt-6 flex flex-wrap items-center justify-between gap-4 text-xs text-ink-500">
            <p class="max-w-[64ch]">
                Vehicles are sold as-is in the condition photographed. Deposit terms and
                the full fee table are published before you bid. Rates shown are updated
                by the platform and are fixed once your deposit is paid.
            </p>
            <p class="tnum">&copy; {{ now()->year }} Opoku Auctions</p>
        </div>
    </div>
</footer>

</body>
</html>