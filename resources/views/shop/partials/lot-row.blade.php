{{-- One row in the live board.

     Taken from candidate 1b and now the only lot list in the product. The
     three-card grid it replaced was prettier but showed three lots; this shows
     ten and answers the question a buyer actually has, which is "what is
     closing and when". Time left is the column that matters most on a live
     board and the card grid had nowhere to put it. --}}
<a href="{{ route('lots.show', $lot) }}"
   class="grid grid-cols-12 gap-4 items-center py-3 border-b border-ink-200 hover:bg-ink-50/70 transition-colors">
    {{-- Sized so the vehicle is recognisable. At 56px every car was an
         unidentifiable sliver: the row carried a photo without carrying
         information. --}}
    <div class="col-span-4 sm:col-span-2">
        <img src="{{ $lot->primaryPhoto() ?? 'https://picsum.photos/seed/opoku-lot-'.$lot->id.'/400/260' }}"
             alt="{{ $lot->title }}"
             width="400" height="260" loading="lazy" decoding="async"
             class="w-full aspect-[3/2] object-cover rounded-[8px] border border-ink-200">
    </div>

    <div class="col-span-8 sm:col-span-4 min-w-0">
        <div class="font-semibold text-ink-900 truncate">{{ $lot->title }}</div>
        {{-- A goods lot has no VIN. Printing one anyway left a blank line
             under every pallet title, which read as a loading failure. --}}
        <div class="text-xs text-ink-500 truncate">
            @if ($lot->isVehicle() && $lot->vehicleDetail?->vin)
                <span class="tnum">{{ $lot->vehicleDetail->vin }}</span>
            @elseif ($lot->goodsDetail)
                <span>{{ $lot->goodsDetail->unit_label }}</span>
                @if ($lot->goodsDetail->manifest_count > 1)
                    <span class="tnum">&middot; {{ $lot->goodsDetail->manifest_count }} pieces</span>
                @endif
            @endif
        </div>
    </div>

    <div class="hidden sm:block col-span-2 text-sm text-ink-600 truncate">{{ $lot->city }}</div>

    <div class="hidden sm:block col-span-2 text-sm">
        <span class="text-xs uppercase tracking-wide text-ink-500">{{ str_replace('_', ' ', $lot->condition) }}</span>
    </div>

    <div class="hidden sm:block col-span-2 text-sm whitespace-nowrap tnum {{ $lot->isAcceptingBids() ? 'text-brick-600 font-semibold' : 'text-ink-400' }}">
        {{ $lot->timeUntilClose() }}
    </div>

    <div class="col-span-12 sm:col-span-1 text-right whitespace-nowrap">
        {{-- An unbid lot showing USD 0 is wrong twice over: the price on the
             lot is not zero, and a bidder would read it as free. Show what it
             opens at until someone actually bids. Stacked, because the bid
             column is one twelfth of the row and inline it wrapped to three
             lines and made every card a different height. --}}
        @if ($lot->current_bid > 0)
            <span class="tnum font-bold text-ink-900">{{ number_format($lot->current_bid / 100) }}</span>
            <span class="text-xs text-ink-500 ml-1">{{ strtoupper($lot->currency_code) }}</span>
        @else
            <div class="text-[10px] uppercase tracking-wide text-ink-400 leading-none">opens at</div>
            <span class="tnum font-bold text-ink-900">{{ number_format($lot->starting_bid / 100) }}</span>
            <span class="text-xs text-ink-500 ml-1">{{ strtoupper($lot->currency_code) }}</span>
        @endif
    </div>
</a>