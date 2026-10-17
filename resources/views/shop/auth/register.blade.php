@extends('layouts.shop')

@section('title', 'Create an account — Opoku Auctions')

@section('content')
<section class="mx-auto max-w-md px-4 sm:px-6 py-16">
    <h1 class="text-3xl font-extrabold tracking-tight text-ink-900">Create an account</h1>
    <p class="mt-2 text-ink-600">It takes about a minute.</p>

    @if ($errors->any())
        <div class="mt-6 bg-brick-50 border border-brick-200 text-brick-900 text-sm px-4 py-3 rounded-[8px]" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('register.store') }}" class="mt-8 space-y-5">
        @csrf
        <div>
            <label class="label" for="name">Full name</label>
            <input id="name" name="name" type="text" autocomplete="name" required
                   value="{{ old('name') }}" class="field">
        </div>
        <div>
            <label class="label" for="email">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" required
                   value="{{ old('email') }}" class="field">
        </div>
        <div>
            <label class="label" for="phone">WhatsApp number</label>
            <input id="phone" name="phone" type="tel" autocomplete="tel" required
                   placeholder="024 000 0000" value="{{ old('phone') }}" class="field">
            <p class="mt-1.5 text-xs text-ink-500">This is where your shipment updates go.</p>
        </div>
        <div>
            <label class="label" for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required class="field">
            <p class="mt-1.5 text-xs text-ink-500">At least 10 characters, with letters and numbers.</p>
        </div>
        <div>
            <label class="label" for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password"
                   autocomplete="new-password" required class="field">
        </div>

        {{-- Not pre-ticked. Consent that arrives pre-selected is not consent. --}}
        <label class="flex items-start gap-2 text-sm text-ink-700">
            <input type="checkbox" name="terms" value="1" required class="accent-brick-600 w-4 h-4 mt-0.5 shrink-0">
            <span>I accept the bidding terms and understand vehicles are sold as-is in the condition shown.</span>
        </label>

        <button type="submit" class="btn btn-primary w-full">Create account</button>
    </form>

    <p class="mt-8 text-sm text-ink-600">
        Already registered?
        <a href="{{ route('login') }}" class="font-semibold text-brick-600 hover:text-brick-700">Sign in</a>.
    </p>
</section>
@endsection
