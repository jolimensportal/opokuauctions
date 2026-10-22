<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Your freight') — Opoku Auctions</title>
    @vite('resources/css/portal.css')
</head>
<body>
{{-- The customer portal's own shell.

     An icon rail and a top bar, the way the reference account area is
     arranged. Deliberately not the storefront's header and footer: this is a
     separate application that happens to share the brand, and a buyer who
     signs in should not be handed a shop page. --}}
<div class="portal-shell">
    <nav class="portal-rail" aria-label="Account">
        <a href="{{ route('freight') }}" class="on" title="Your freight">◱</a>
        <a href="{{ route('lots.index') }}" title="Find cars">◈</a>
        <a href="{{ route('send.create') }}" title="Send a parcel">▦</a>
        <a href="{{ route('orders.index') }}" title="Your parcels">◎</a>
        <a href="{{ route('wallet') }}" title="Your wallet">$</a>
        <a href="{{ route('dashboard') }}" title="My account">▤</a>
    </nav>

    <div>
        <header class="portal-topbar">
            <span class="brand"><span class="mark">Opoku</span><span class="accent">Auctions</span></span>
            <nav class="topnav">
                <a href="{{ route('lots.index') }}">Find cars</a>
                <a href="{{ route('how-it-works') }}">How to buy</a>
                <a href="{{ route('fees') }}">Fees</a>
                <a href="{{ route('freight') }}" class="on">My freight</a>
            </nav>
            <div class="topbar-right">
                <span class="icon-btn" title="Prices in USD">$</span>
                <span class="who">{{ auth()->user()?->name }}</span>
                <span class="avatar" style="background: var(--brick-500);">
                    {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(auth()->user()?->name ?? 'U', 0, 1)) }}
                </span>
            </div>
        </header>

        <main class="portal-main">
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
