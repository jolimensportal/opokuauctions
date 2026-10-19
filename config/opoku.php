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

];