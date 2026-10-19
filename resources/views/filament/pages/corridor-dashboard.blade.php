{{-- The operator's day, ordered by how expensive a queue is to leave alone.

     Needs-money and Unallocated sit at the top because "money in, car not
     moving" is the most expensive idle state in the platform. Everything below
     is shipping progress, which waits on the physical world anyway.

     Built on Filament's own components, NOT on Tailwind utilities. The admin
     panel does not load the application's compiled stylesheet, so a class like
     bg-red-50 written in this file is inert and the whole page renders as bare
     text — which is exactly what happened here: every card below looked like
     a paragraph, because nothing in the grid was ever real markup. --}}
<x-filament-panels::page>
    @php
        $money = fn ($n) => '$' . number_format(((int) $n) / 100, 2);
    @endphp

    {{-- Counts, worst first. Drift is called out because it means the numbers
         above it cannot be trusted. --}}
    <div style="display: flex; flex-wrap: wrap; gap: 1rem;">
        @foreach ([
            ['Needs money', $summary['needs_money'], 'danger'],
            ['Unallocated', $summary['unallocated'], 'warning'],
            ['Needs booking', $summary['needs_booking'], 'warning'],
            ['In transit', $summary['in_transit'], 'gray'],
            ['At Tema', $summary['at_tema'], 'info'],
            ['Disputed', $summary['disputed'], 'danger'],
            ['Drift', $summary['drift'], $summary['drift'] > 0 ? 'danger' : 'success'],
        ] as [$label, $count, $tone])
            <x-filament::section compact style="width: 15rem; flex: 0 0 auto;">
                <div class="fi-size-xs fi-font-medium text-gray-500 dark:text-gray-400">
                    {{ $label }}
                </div>
                    <x-filament::badge :color="$tone === 'success' || $count === 0 ? 'gray' : $tone" size="lg">
                        {{ $count }}
                    </x-filament::badge>
            </x-filament::section>
        @endforeach
    </div>

    @if ($drift['count'] > 0)
        {{-- Said before the numbers, because the numbers below are derived from
             the wallets that disagree. --}}
        <x-filament::section class="mt-6" icon="heroicon-m-exclamation-triangle">
            <x-slot name="heading">
                {{ $drift['count'] }} wallet(s) disagree with their own ledger
            </x-slot>

            <x-slot name="description">
                Balances on this page cannot be trusted until this is resolved. Every figure
                here is derived from these wallets.
            </x-slot>

            <div class="fi-prose">
                <ul class="list-disc list-inside">
                    @foreach ($drift['rows'] as $row)
                        <li class="font-mono">{{ $row->user_id }} ({{ strtoupper($row->currency_code) }})</li>
                    @endforeach
                </ul>
            </div>
        </x-filament::section>
    @endif

    <div style="display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr)); margin-top: 1.5rem;">
        {{-- 1. Needs money --}}
        <x-filament::section>
            <x-slot name="heading">Needs money</x-slot>
            <x-slot name="description">Sold lots whose balance is unpaid. Blocks loading.</x-slot>

            <div class="-mx-6 overflow-x-auto"><table class="w-full text-left">
                @forelse ($needsMoney['rows'] as $lot)
                    <tr class="border-b border-gray-100 last:border-0">
                        <td class="px-6 py-2.5 text-gray-700 dark:text-gray-300">{{ $lot->title }}</td>
                        <td class="px-6 py-2.5 text-right font-mono">{{ $money($lot->current_bid) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="py-10 text-center text-gray-500">Nothing outstanding.</td></tr>
                @endforelse
            </table></div>
        </x-filament::section>

        {{-- 2. Unallocated --}}
        <x-filament::section>
            <x-slot name="heading">Unallocated</x-slot>
            <x-slot name="description">Paid, no container. The most expensive idle state.</x-slot>

            <div class="-mx-6 overflow-x-auto"><table class="w-full text-left">
                @forelse ($unallocated['rows'] as $lot)
                    <tr class="border-b border-gray-100 last:border-0">
                        <td class="px-6 py-2.5 text-gray-700 dark:text-gray-300">
                            {{ $lot->title }}
                            <span class="text-gray-500">· {{ $lot->city }}</span>
                        </td>
                        <td class="px-6 py-2.5 text-right font-mono">{{ $money($lot->current_bid) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="py-10 text-center text-gray-500">Every paid lot has a container.</td></tr>
                @endforelse
            </table></div>
        </x-filament::section>

        {{-- 3. Needs booking --}}
        <x-filament::section>
            <x-slot name="heading">Needs booking</x-slot>
            <x-slot name="description">No forwarder or no container number yet.</x-slot>

            <div class="-mx-6 overflow-x-auto"><table class="w-full text-left">
                @forelse ($needsBooking['rows'] as $shipment)
                    <tr class="border-b border-gray-100 last:border-0">
                        <td class="px-6 py-2.5 text-gray-700 dark:text-gray-300" class="font-mono">{{ $shipment->container_no ?? 'no container no.' }}</td>
                        <td class="px-6 py-2.5 text-right text-gray-500">{{ $shipment->seaport_destination ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="py-10 text-center text-gray-500">All booked.</td></tr>
                @endforelse
            </table></div>
        </x-filament::section>

        {{-- 4. In transit --}}
        <x-filament::section>
            <x-slot name="heading">
                In transit
                @if ($inTransit['stale'] > 0)
                    <x-filament::badge color="danger" size="sm">{{ $inTransit['stale'] }} stale</x-filament::badge>
                @endif
            </x-slot>
            <x-slot name="description">Nothing moved for 3+ days is a support queue waiting to happen.</x-slot>

            <div class="-mx-6 overflow-x-auto"><table class="w-full text-left">
                @forelse ($inTransit['rows'] as $shipment)
                    <tr class="border-b border-gray-100 last:border-0">
                        <td class="px-6 py-2.5 text-gray-700 dark:text-gray-300" class="font-mono">{{ $shipment->container_no }}</td>
                        <td class="px-6 py-2.5 text-right">
                            <span class="text-gray-500">{{ $shipment->last_milestone ?? 'no milestones' }}</span>
                            @if ($shipment->days_stale !== null && $shipment->days_stale >= 3)
                                <span class="text-danger-600 dark:text-danger-400 block fi-size-sm">silent {{ $shipment->days_stale }} day(s)</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="py-10 text-center text-gray-500">Nothing at sea.</td></tr>
                @endforelse
            </table></div>
        </x-filament::section>

        {{-- 5. At Tema --}}
        <x-filament::section>
            <x-slot name="heading">At Tema</x-slot>
            <x-slot name="description">Berthed, clearing customs or waiting for gate-out.</x-slot>

            <div class="-mx-6 overflow-x-auto"><table class="w-full text-left">
                @forelse ($atTema['rows'] as $shipment)
                    <tr class="border-b border-gray-100 last:border-0">
                        <td class="px-6 py-2.5 text-gray-700 dark:text-gray-300" class="font-mono">{{ $shipment->container_no }}</td>
                        <td class="px-6 py-2.5 text-right text-gray-500">{{ $shipment->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="py-10 text-center text-gray-500">Nothing at Tema.</td></tr>
                @endforelse
            </table></div>
        </x-filament::section>

        {{-- 6. Disputed --}}
        <x-filament::section>
            <x-slot name="heading">Disputed</x-slot>
            <x-slot name="description">Funds held. Nothing here auto-resolves — only an Owner may release.</x-slot>

            <div class="-mx-6 overflow-x-auto"><table class="w-full text-left">
                @forelse ($disputed['rows'] as $shipment)
                    <tr class="border-b border-gray-100 last:border-0">
                        <td class="px-6 py-2.5 text-gray-700 dark:text-gray-300" class="font-mono">{{ $shipment->container_no }}</td>
                        <td class="px-6 py-2.5 text-right text-danger-600 dark:text-danger-400">{{ $shipment->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="py-10 text-center text-gray-500">No disputes.</td></tr>
                @endforelse
            </table></div>
        </x-filament::section>
    </div>
</x-filament-panels::page>