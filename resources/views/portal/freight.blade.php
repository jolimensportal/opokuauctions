@extends('portal.layout')

@section('title', 'Your freight')

@section('content')
    @php
        $money = fn ($cents) => '$' . number_format(((int) $cents) / 100, 2);
    @endphp

    {{-- The welcome banner, in the reference screenshot's arrangement. --}}
    <section class="banner">
        <h2>Opoku welcomes you, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}.</h2>
        <p>
            From your account you can see the cars we are carrying for you, the parcels you have
            sent us, and anything we still need from you before it can move.
        </p>
    </section>

    <div class="grid tiles-3" style="margin-top: 16px;">
        <div class="card">
            <div class="tile">
                <span class="disc">◈</span>
                <div>
                    <div class="label">Cars arriving</div>
                    <div class="fig tnum">{{ $counters['carsArriving'] }}</div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="tile">
                <span class="disc">▦</span>
                <div>
                    <div class="label">Parcels you sent</div>
                    <div class="fig tnum">{{ $counters['parcelsSent'] }}</div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="tile">
                <span class="disc">$</span>
                <div>
                    <div class="label">Your balance</div>
                    <div class="fig tnum">{{ $money($counters['balanceCents']) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid split" style="margin-top: 16px;">
        <section class="card">
            <h2>Your freight</h2>
            <p class="sub">Everything moving to Tema that has your name on it.</p>

            @forelse ($cars as $car)
                <div style="padding: 12px 0 10px; border-bottom: 1px solid var(--ink-100);">
                    <div class="row between">
                        <div>
                            <div class="strong" style="font-size: 14.5px;">{{ $car['title'] }}</div>
                            <div class="muted" style="font-size: 12.5px;">
                                {{ $car['container_no'] ?? 'No container yet' }}
                                @if ($car['vessel']) · {{ $car['vessel'] }} @endif
                                @if ($car['eta']) · arriving {{ $car['eta']->toFormattedDateString() }} @endif
                            </div>
                        </div>
                        <span class="chip {{ $car['cleared'] ? 'ok' : 'warn' }}">
                            {{ $car['cleared'] ? 'cleared' : strtolower((string) $car['status']) }}
                        </span>
                    </div>
                </div>

                <ul class="timeline" style="margin-top: 14px;">
                    @foreach ($car['milestones'] as $milestone)
                        <li>
                            <span class="rail"><span class="node {{ $milestone->type === 'SAILING' ? 'now' : '' }}"></span></span>
                            <div>
                                <div class="what">{{ \Illuminate\Support\Str::headline($milestone->type) }}</div>
                                <div class="meta">{{ $milestone->notes ?? 'Recorded movement' }}</div>
                            </div>
                            <span class="when">{{ $milestone->occurred_at->toFormattedDateString() }}</span>
                        </li>
                    @endforeach
                </ul>
            @empty
                <p class="muted">Nothing is on the water for you yet.</p>
            @endforelse

            @if ($freightBilledCents > 0)
                <div style="margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--ink-100);">
                    <div class="tile">
                        <span class="disc">$</span>
                        <div>
                            <div class="label">Freight billed to Tema</div>
                            <div class="fig tnum">{{ $money($freightBilledCents) }}</div>
                            <div class="delta">duty, VAT and port charges — all receipted</div>
                        </div>
                    </div>
                </div>
            @endif
        </section>

        <section class="card">
            <h2>Required action</h2>
            <p class="sub">What we are waiting on you for.</p>

            @forelse ($actions as $action)
                <div class="action-row">
                    <span class="disc">!</span>
                    <div class="grow">
                        <div class="t">{{ $action['title'] }}</div>
                        <div class="m">{{ $action['detail'] }}</div>
                        <div class="action-link">{{ $action['action'] }}</div>
                    </div>
                    <span class="chip {{ $action['tone'] }}">{{ $action['tag'] }}</span>
                </div>
            @empty
                <p class="muted">Nothing is waiting on you.</p>
            @endforelse
        </section>
    </div>
@endsection
