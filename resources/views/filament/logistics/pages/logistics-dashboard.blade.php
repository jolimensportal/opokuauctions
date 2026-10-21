{{-- The freight console.

     Built on the design system's own classes from resources/css/filament/theme.css.
     The admin panel loads that stylesheet through the panel's viteTheme, so
     these classes are real here — which is exactly what was not true before,
     when the panel loaded only Filament's stylesheet and every class written
     in an admin blade was inert. --}}
<x-filament-panels::page>
    @php
        $money = fn ($cents) => '$' . number_format(((int) $cents) / 100, 2);
        $lastMovement = fn ($shipment) => $shipment->milestones->sortByDesc('occurred_at')->first();
    @endphp

    {{-- Pipeline counters. --}}
    <div class="grid tiles-4">
        @foreach ([
            ['In transit', $counters['inTransit'], false],
            ['At Tema', $counters['atTema'], false],
            ['Parcels booked', $counters['parcelsBooked'], false],
            ['Need attention', $counters['needAttention'], true],
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

    <div class="grid split" style="margin-top: 1rem;">
        {{-- What is stopped. --}}
        <section class="card">
            <h2>Required action</h2>
            <p class="sub">
                @if (count($exceptions) === 0)
                    Nothing is stopped. Everything else is moving.
                @else
                    {{ count($exceptions) }} {{ \Illuminate\Support\Str::plural('thing is', count($exceptions)) }} stopped.
                @endif
            </p>

            @forelse ($exceptions as $exception)
                <div class="action-row">
                    <span class="disc">!</span>
                    <div class="grow">
                        <div class="t">{{ $exception['title'] }}</div>
                        <div class="m">{{ $exception['detail'] }}</div>
                    </div>
                    <span class="chip">{{ $exception['action'] }}</span>
                </div>
            @empty
                <p class="muted">Nothing needs attention.</p>
            @endforelse
        </section>

        {{-- The money, in lines. --}}
        <section class="card">
            <h2>Money moving</h2>
            <p class="sub">Freight and insurance on the water, in lines rather than one number.</p>

            <div class="stack" style="gap: 14px;">
                @forelse ($shipments as $shipment)
                    <div>
                        <div class="row between" style="font-size: 12.5px;">
                            <span class="muted">{{ $shipment->container_no }}</span>
                            <span class="tnum">{{ $money($shipment->freight_cost + $shipment->insurance_cost) }}</span>
                        </div>
                        <div class="bar" style="margin-top: 6px;">
                            <span style="width: {{ $shipment->freight_cost > 0 ? 60 : 4 }}%"></span>
                        </div>
                    </div>
                @empty
                    <p class="muted">Nothing is on the water yet.</p>
                @endforelse
            </div>

            @if ($shipments->isNotEmpty())
                <div style="margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--ink-100);">
                    <div class="tile">
                        <span class="disc">$</span>
                        <div>
                            <div class="label">Customer balance outstanding</div>
                            <div class="fig tnum">$0.00</div>
                        </div>
                    </div>
                </div>
            @endif
        </section>
    </div>

    {{-- Every consignment, both lanes. --}}
    <section class="card flush" style="margin-top: 1rem;">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 18px 20px 14px;">
            <div>
                <h2 style="margin: 0;">Consignments</h2>
                <p class="sub" style="margin: 3px 0 0;">One container we carry, one parcel a customer sent.</p>
            </div>
            <x-filament::button tag="a" href="{{ \App\Filament\Logistics\Resources\Shipments\ShipmentResource::getUrl('index') }}">
                All containers
            </x-filament::button>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Contents</th>
                    <th>Route</th>
                    <th>State</th>
                    <th>Last movement</th>
                    <th>Money</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($shipments as $shipment)
                    @php
                        $movement = $lastMovement($shipment);
                        $days = \App\Services\ShipmentStaleness::daysSince($shipment);
                    @endphp
                    <tr>
                        <td class="mono strong">{{ $shipment->container_no }}
                            <span class="sub2">{{ $shipment->vessel ?? 'No vessel recorded' }}</span>
                        </td>
                        <td>{{ $shipment->lots()->count() }} vehicles</td>
                        <td>{{ $shipment->seaport_origin ?? '?' }} → {{ $shipment->seaport_destination ?? '?' }}</td>
                        <td><span class="chip">{{ $shipment->status }}</span></td>
                        <td class="mono">
                            {{ $movement?->type ?? '—' }}
                            @if ($movement && \App\Services\ShipmentStaleness::isSilent($shipment))
                                <span class="sub2" style="color: var(--brick-600);">silent {{ $days }} days</span>
                            @endif
                        </td>
                        <td class="mono">{{ $money($shipment->freight_cost + $shipment->insurance_cost) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="muted" style="padding: 24px 16px; text-align: center;">
                            Nothing is on the water yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-filament-panels::page>
