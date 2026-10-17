{{-- Column headings for the live board.

     Extracted because the landing page and /lots both render it, and the two
     copies drifted apart once already: /lots had the headings and the landing
     page did not. --}}
<div class="hidden sm:grid grid-cols-12 gap-4 text-xs uppercase tracking-[0.14em] text-ink-400">
    <span class="col-span-2"></span>
    <span class="col-span-3">Vehicle</span>
    <span class="col-span-2">Location</span>
    <span class="col-span-2">Condition</span>
    {{-- Two columns, not one: "1 hr 22 min" is four words and wrapped onto two
         lines in a single column, which made every row a different height. --}}
    <span class="col-span-2">Closes</span>
    <span class="col-span-1 text-right">Bid</span>
</div>