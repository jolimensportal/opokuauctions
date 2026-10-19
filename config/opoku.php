<?php

return [

    /*
    |---------------------------------------------------------------------------
    | The number a sender can reach us on
    |---------------------------------------------------------------------------
    |
    | Shown on the quote page as a WhatsApp handoff, prefilled with the quote
    | they just asked for. It is a demand signal that costs an afternoon:
    | `/send` already produces a price, so the cheapest way to find out whether
    | anyone will actually pay it is a link that opens a conversation, rather
    | than a booking flow built on an unvalidated assumption.
    |
    | Digits only, no +, no spaces — wa.me needs that. Blank means the button
    | is not rendered at all: linking to a placeholder number sends a real
    | customer's parcel enquiry into a void, which is worse than offering no
    | way to enquire.
    |
    */

    'whatsapp' => env('OPOKU_WHATSAPP', ''),

    /*
    |---------------------------------------------------------------------------
    | What currency the payment link is raised in
    |---------------------------------------------------------------------------
    |
    | Must match the currency the quote was given in. The quote page prices in
    | USD and the sender pays from North America, so USD is the default and the
    | figure on the booking passes through unchanged.
    |
    | It has to be a currency the merchant has actually enabled in the Paystack
    | dashboard. A test account with only GHS enabled rejects USD with a 403
    | "Currency not supported by merchant" — which is a two-minute dashboard
    | setting, not something to paper over.
    |
    | Raising this to a currency other than the quote's is NOT supported. USD and
    | GHS both subdivide by 100, so a mismatched currency still produces a
    | plausible-looking integer and quietly charges the wrong money — GHS 25 for
    | a USD 25.00 quote is about $1.67. If we ever need to charge in cedi, the
    | amount has to be converted at a real rate first.
    |
    */

    'payment_currency' => env('OPOKU_PAYMENT_CURRENCY', 'USD'),

];