@extends('layouts.shop')

@section('title', 'Sign in — Opoku Auctions')

@section('content')
<section class="mx-auto max-w-md px-4 sm:px-6 py-16">
    <h1 class="text-3xl font-extrabold tracking-tight text-ink-900">Sign in</h1>

    @if ($errors->any())
        <div class="mt-6 bg-brick-50 border border-brick-200 text-brick-900 text-sm px-4 py-3 rounded-[8px]" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="mt-8 space-y-5">
        @csrf
        <div>
            <label class="label" for="email">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" required
                   value="{{ old('email') }}" class="field">
        </div>
        <div>
            <label class="label" for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required class="field">
        </div>
        <label class="flex items-center gap-2 text-sm text-ink-700">
            <input type="checkbox" name="remember" value="1" class="accent-brick-600 w-4 h-4">
            Keep me signed in
        </label>
        <button type="submit" class="btn btn-primary w-full">Sign in</button>
    </form>

    <p class="mt-8 text-sm text-ink-600">
        No account yet?
        <a href="{{ route('register') }}" class="font-semibold text-brick-600 hover:text-brick-700">Create one</a>.
    </p>
</section>
@endsection
