{{--
    The operations console.

    Built on Filament's own components rather than Tailwind utilities, because
    the admin panel does not load the application's compiled stylesheet — a
    class like bg-red-50 written in this file is inert here and silently renders
    as unstyled text. Anything inside /admin must use Filament's components or
    Filament's own colour tokens.

    Same design intent as the customer surfaces — one brand, two densities. The
    queue is the hero rather than a set of cards, because nine exceptions cannot
    be triaged in a marketing layout.

    There is no dismiss control. An item leaves the queue when the row behind it
    is repaired, so the only action is "Resolve": a link to the thing that has to
    change.
--}}
<x-filament-panels::page>
    {{-- A failing detector must never look like a quiet queue. --}}
    @if (count($failed) > 0)
        <x-filament::section>
            <x-slot name="heading">
                <span class="text-danger-600 dark:text-danger-400">
                    {{ count($failed) }} of 7 checks could not run
                </span>
            </x-slot>
            <x-slot name="description">
                The queue below is incomplete. Do not read it as a full picture.
            </x-slot>

            <ul class="space-y-1 font-mono text-xs text-gray-500 dark:text-gray-400">
                @foreach ($failed as $kind => $reason)
                    <li>{{ $kind }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    {{-- Severity tiles. No icons: at this density the number is the content,
         and a coloured glyph beside every figure is noise. --}}
    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
        @foreach ([
            ['Critical', 'danger'],
            ['High', 'warning'],
            ['Medium', 'info'],
            ['Low', 'gray'],
        ] as [$label, $colour])
            <x-filament::section style="flex: 1 1 12rem;">
                <p class="fi-size-xs fi-font-medium text-gray-500 dark:text-gray-400">
                    {{ $label }}
                </p>
                <p class="fi-font-bold tabular-nums mt-1" style="font-size: 1.875rem; line-height: 2.25rem;"
                          {{ match ($colour) {
                              'danger' => 'text-danger-600 dark:text-danger-400',
                              'warning' => 'text-warning-600 dark:text-warning-400',
                              'info' => 'text-info-600 dark:text-info-400',
                              default => 'text-gray-500 dark:text-gray-400',
                          } }}">
                    {{ $severityCounts[$label] ?? 0 }}
                </p>
            </x-filament::section>
        @endforeach
    </div>

    @if ($items->isEmpty())
        <x-filament::section>
            <div class="py-10 text-center">
                <p class="text-base font-semibold text-gray-950 dark:text-white">
                    Nothing needs attention
                </p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Every clearance task is evidenced, every shipment is allocated and
                    cleared, and every live lot has a weight.
                </p>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Needs attention</x-slot>
            <x-slot name="description">
                {{ $items->count() }} open {{ Str::plural('exception', $items->count()) }},
                most severe first.
            </x-slot>

            <div class="-mx-6 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            @foreach (['Reference', 'Severity', 'Issue', 'Waiting', 'Owner'] as $heading)
                                <th class="whitespace-nowrap px-6 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ $heading }}
                                </th>
                            @endforeach
                            <th class="px-6 py-2"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($items as $item)
                            <tr class="border-b border-gray-100 last:border-0 hover:bg-gray-50 dark:border-white/5 dark:hover:bg-white/5">
                                <td class="whitespace-nowrap px-6 py-2.5 font-mono text-xs text-gray-950 dark:text-white">
                                    {{ $item->ref }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-2.5">
                                    <x-filament::badge :color="$item->severity->color()">
                                        {{ $item->severity->label() }}
                                    </x-filament::badge>
                                </td>
                                <td class="px-6 py-2.5 text-gray-700 dark:text-gray-300">
                                    {{ $item->label }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-2.5 tabular-nums text-gray-500 dark:text-gray-400">
                                    @php $age = $item->ageInDays(now()->toImmutable()); @endphp
                                    {{ $age }} {{ Str::plural('day', $age) }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-2.5">
                                    @if ($item->isUnassigned())
                                        <x-filament::badge color="gray">Unassigned</x-filament::badge>
                                    @else
                                        <span class="text-xs text-gray-700 dark:text-gray-300">
                                            {{ $ownerNames[$item->ownerId] ?? 'Former staff member' }}
                                        </span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-6 py-2.5 text-right">
                                    <a href="{{ $item->actionUrl }}"
                                       class="text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400">
                                        Resolve
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>