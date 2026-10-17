{{-- A lot as a price-first tile.

     Built to the shape auction buyers actually scan: photograph, what it is,
     condition, and then the two numbers that decide whether they click — what it
     costs now and how long they have. The current bid is the largest type on the
     card, larger than the vehicle name, because that is the reading order of
     this category. --}}
<article class="surface overflow-hidden flex flex-col">
    <a href="{{ route('lots.show', $lot) }}" class="flex flex-col flex-1 group">
        <div class="relative">
            <img src="{{ $lot->primaryPhoto() ?? 'https://picsum.photos/seed/opoku-lot-'.$lot->id.'/600/400' }}"
                 alt="{{ $lot->title }}"
                 width="600" height="400" loading="lazy" decoding="async"
                 class="w-full aspect-[3/2] object-cover">

            {{-- Condition is the one fact that changes what a car is worth, so
                 it sits on the photograph where it cannot be missed. --}}
            <span class="absolute left-2.5 top-2.5 bg-ink-950/90 text-paper text-[0.6875rem] font-semibold uppercase tracking-[0.1em] px-2 py-1 rounded-[3px]">
                {{ str_replace('_', ' ', $lot->condition) }}
            </span>

            @if ($lot->isAcceptingBids())
                <span class="absolute right-2.5 top-2.5 bg-brick-500 text-paper text-[0.6875rem] font-semibold px-2 py-1 rounded-[3px] tnum">
                    {{ $lot->timeUntilClose() }}
                </span>
            @endif
        </div>

        <div class="p-4 flex flex-col flex-1">
            <h3 class="font-semibold leading-snug text-ink-900 group-hover:text-brick-600 transition-colors">
                {{ $lot->title }}
            </h3>
            <p class="mt-1 text-xs text-ink-500 tnum">
                @if ($lot->vehicleDetail?->odometer)
                    {{ $lot->city }} &middot; {{ number_format($lot->vehicleDetail->odometer) }} mi
                @else
                    {{ $lot->city }} &middot; {{ $lot->weight_kg ? number_format((float) $lot->weight_kg).' kg' : 'Weight on request' }}
                @endif
            </p>

            <div class="mt-auto pt-4 flex items-end justify-between gap-3">
                <div>
                    <div class="text-[0.6875rem] uppercase tracking-[0.1em] text-ink-400">Current bid</div>
                    <div class="mt-0.5 text-2xl font-bold text-ink-900 tnum leading-none">
                        {{ number_format($lot->current_bid / 100) }}
                        <span class="text-xs font-semibold text-ink-400">{{ strtoupper($lot->currency_code) }}</span>
                    </div>
                </div>

                @if ($lot->bid_count > 0)
                    <span class="text-xs text-ink-400 tnum shrink-0">{{ $lot->bid_count }} bid{{ $lot->bid_count === 1 ? '' : 's' }}</span>
                @else
                    <span class="text-xs text-ink-400 shrink-0">No bids yet</span>
                @endif
            </div>
        </div>
    </a>
</article>