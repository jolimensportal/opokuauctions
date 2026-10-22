{{-- The operations console.

     The same card system as the freight console, because it is the same
     product. The data comes from OperationsOverview, which is tested without
     a browser; this file is a view over it. --}}
<x-filament-panels::page>
    @php
        $money = fn ($cents) => '$' . number_format(((int) $cents) / 100, 2);
    @endphp

    <div class="grid tiles-4">
        @foreach ([
            ['Lots live', $summary['lotsLive'], false],
            ['Open bids', $summary['openBids'], false],
            ['Money owed to us', $money($summary['moneyOwedCents']), false],
            ['Funds held', $summary['heldOrDisputed'], $summary['heldOrDisputed'] > 0],
        ] as [$label, $value, $alert])
            <div class="card">
                <div class="tile">
                    <span class="disc {{ $alert ? 'alt' : '' }}">{{ $alert ? '!' : '◈' }}</span>
                    <div>
                        <div class="label">{{ $label }}</div>
                        <div class="fig tnum">{{ $value }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid panels-3" style="margin-top: 1rem;">
        <section class="card">
            <h2>Recent activity</h2>
            <p class="sub">Everything that happened, newest first.</p>

            <ul class="timeline">
                @forelse ($activity as $event)
                    <li>
                        <span class="rail"><span class="node"></span></span>
                        <div>
                            <div class="what">{{ $event['title'] }}</div>
                            @if ($event['detail'])
                                <div class="meta">{{ $event['detail'] }}</div>
                            @endif
                        </div>
                        <span class="when">{{ \Illuminate\Support\Carbon::parse($event['at'])->toFormattedDateString() }}</span>
                    </li>
                @empty
                    <p class="muted">Nothing has happened yet.</p>
                @endforelse
            </ul>
        </section>

        <section class="card">
            <h2>Money at risk</h2>
            <p class="sub">Taken, unpaid, or not what the books say.</p>

            <div class="stack" style="gap: 14px;">
                <div class="row between" style="font-size: 12.5px;">
                    <span class="muted">Sales won and not paid for</span>
                    <span class="tnum">{{ $moneyAtRisk['needsMoney']['count'] }} · {{ $money($moneyAtRisk['needsMoney']['cents']) }}</span>
                </div>
                <div class="row between" style="font-size: 12.5px;">
                    <span class="muted">Wallets that disagree with their own ledger</span>
                    <span class="tnum">{{ $moneyAtRisk['drift']['count'] }}</span>
                </div>
            </div>

            @if ($moneyAtRisk['drift']['count'] > 0)
                <p class="hint" style="margin-top: 14px;">
                    The numbers above cannot be trusted while a wallet disagrees with its own ledger.
                </p>
            @endif
        </section>

        <section class="card">
            <h2>Required action</h2>
            <p class="sub">What is waiting on a person, and who.</p>

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
</x-filament-panels::page>
