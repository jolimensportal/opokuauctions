{{-- The operator's day, ordered by how expensive a queue is to leave alone.

     Needs-money and Unallocated sit at the top because "money in, car not
     moving" is the most expensive idle state in the platform. Everything below
     is shipping progress, which waits on the physical world anyway. --}}
<x-filament-panels::page>
    @php
        $money = fn ($n) => '$' . number_format(((int) $n) / 100, 2);
    @endphp

    {{-- Counts, worst first. Drift is called out because it means the numbers
         above it cannot be trusted. --}}
    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3 mb-8">
        @foreach ([
            ['Needs money', $summary['needs_money'], 'danger'],
            ['Unallocated', $summary['unallocated'], 'warning'],
            ['Needs booking', $summary['needs_booking'], 'warning'],
            ['In transit', $summary['in_transit'], 'gray'],
            ['At Tema', $summary['at_tema'], 'info'],
            ['Disputed', $summary['disputed'], 'danger'],
            ['Drift', $summary['drift'], $summary['drift'] > 0 ? 'danger' : 'success'],
        ] as [$label, $count, $tone])
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <div class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</div>
                <div class="text-2xl font-semibold
                    {{ $tone === 'danger' && $count > 0 ? 'text-red-600' : '' }}
                    {{ $tone === 'success' ? 'text-green-600' : '' }}">
                    {{ $count }}
                </div>
            </div>
        @endforeach
    </div>

    @if ($drift['count'] > 0)
        <div class="mb-8 rounded-lg border border-red-300 bg-red-50 dark:bg-red-950 p-4">
            <div class="font-semibold text-red-800 dark:text-red-200">
                {{ $drift['count'] }} wallet(s) disagree with their own ledger
            </div>
            <p class="text-sm text-red-700 dark:text-red-300 mt-1">
                Balances above cannot be trusted until this is resolved. Every figure on this page
                is derived from these wallets.
            </p>
            <ul class="mt-2 text-sm">
                @foreach ($drift['rows'] as $row)
                    <li class="font-mono">{{ $row->user_id }} ({{ strtoupper($row->currency_code) }})</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- 1. Needs money --}}
        <section class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="font-semibold mb-1">Needs money</h2>
            <p class="text-xs text-gray-500 mb-3">Sold lots whose balance is unpaid. Blocks loading.</p>
            @forelse ($needsMoney['rows'] as $lot)
                <div class="flex justify-between py-1 text-sm border-b border-gray-100 dark:border-gray-800">
                    <span>{{ $lot->title }}</span>
                    <span class="font-mono">{{ $money($lot->current_bid) }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-400">Nothing outstanding.</p>
            @endforelse
        </section>

        {{-- 2. Unallocated --}}
        <section class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="font-semibold mb-1">Unallocated</h2>
            <p class="text-xs text-gray-500 mb-3">Paid, no container. The most expensive idle state.</p>
            @forelse ($unallocated['rows'] as $lot)
                <div class="flex justify-between py-1 text-sm border-b border-gray-100 dark:border-gray-800">
                    <span>{{ $lot->title }} <span class="text-gray-400">· {{ $lot->city }}</span></span>
                    <span class="font-mono">{{ $money($lot->current_bid) }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-400">Every paid lot has a container.</p>
            @endforelse
        </section>

        {{-- 3. Needs booking --}}
        <section class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="font-semibold mb-1">Needs booking</h2>
            <p class="text-xs text-gray-500 mb-3">No forwarder or no container number yet.</p>
            @forelse ($needsBooking['rows'] as $shipment)
                <div class="flex justify-between py-1 text-sm border-b border-gray-100 dark:border-gray-800">
                    <span class="font-mono">{{ $shipment->container_no ?? 'no container no.' }}</span>
                    <span class="text-gray-500">{{ $shipment->seaport_destination ?? '—' }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-400">All booked.</p>
            @endforelse
        </section>

        {{-- 4. In transit --}}
        <section class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="font-semibold mb-1">
                In transit
                @if ($inTransit['stale'] > 0)
                    <span class="text-sm font-normal text-red-600">({{ $inTransit['stale'] }} stale)</span>
                @endif
            </h2>
            <p class="text-xs text-gray-500 mb-3">Nothing moved for 3+ days is a support queue waiting to happen.</p>
            @forelse ($inTransit['rows'] as $shipment)
                <div class="py-1 text-sm border-b border-gray-100 dark:border-gray-800">
                    <div class="flex justify-between">
                        <span class="font-mono">{{ $shipment->container_no }}</span>
                        <span class="text-gray-500">{{ $shipment->last_milestone ?? 'no milestones' }}</span>
                    </div>
                    @if ($shipment->days_stale !== null && $shipment->days_stale >= 3)
                        <div class="text-xs text-red-600">silent {{ $shipment->days_stale }} day(s)</div>
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-400">Nothing at sea.</p>
            @endforelse
        </section>

        {{-- 5. At Tema --}}
        <section class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="font-semibold mb-1">At Tema</h2>
            <p class="text-xs text-gray-500 mb-3">Berthed, clearing customs or waiting for gate-out.</p>
            @forelse ($atTema['rows'] as $shipment)
                <div class="flex justify-between py-1 text-sm border-b border-gray-100 dark:border-gray-800">
                    <span class="font-mono">{{ $shipment->container_no }}</span>
                    <span class="text-gray-500">{{ $shipment->status }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-400">Nothing at Tema.</p>
            @endforelse
        </section>

        {{-- 6. Disputed --}}
        <section class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="font-semibold mb-1">Disputed</h2>
            <p class="text-xs text-gray-500 mb-3">Funds held. Nothing here auto-resolves — only an Owner may release.</p>
            @forelse ($disputed['rows'] as $shipment)
                <div class="flex justify-between py-1 text-sm border-b border-gray-100 dark:border-gray-800">
                    <span class="font-mono">{{ $shipment->container_no }}</span>
                    <span class="text-red-600">{{ $shipment->status }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-400">No disputes.</p>
            @endforelse
        </section>
    </div>
</x-filament-panels::page>
