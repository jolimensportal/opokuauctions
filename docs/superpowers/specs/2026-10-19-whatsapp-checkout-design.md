# WhatsApp Checkout — Design

**Date:** 2026-10-19
**Status:** Draft for review — no code written
**Branch:** `multi-kind-lots-and-ops-console`

## Why this exists

`/send` quotes a parcel and offers two ways to continue: leave an email, or open
a WhatsApp chat. Both currently go nowhere commercially. The quote page can
produce a price, but there is no way to pay it.

The business wants that closed inside WhatsApp rather than on a web checkout,
because that is how its customers actually buy: Ghana and its diaspora, mobile
money first, a person to message when something is unclear.

## What is actually available, and what is not

The instinct — "WhatsApp has payments, automate it" — is right about automation
and wrong about the rail. Worth separating clearly, because building against the
wrong one wastes weeks.

**Not available:** Meta's native in-chat Payments API. `order_details`
interactive messages and payment-status webhooks are live in **India and
Brazil** (with business-only Singapore), and the Brazil implementation is built
on Pix. **Ghana is not a participating market**, so there is no Meta
`order_details` message to send.

**Available and field-tested in Ghana:** Paystack (Stripe-owned) payment links,
sent inside the WhatsApp thread, collecting **MTN MoMo, Telecel Cash,
AirtelTigo Money**, cards and bank transfer. Paystack webhooks the confirmation
back to us. The customer never leaves the conversation for long, and nobody
verifies a screenshot.

This spec is for the Paystack path.

## Where we already are

| | State |
|---|---|
| `TwilioWhatsAppGateway` | **Outbound only.** One method, `send()`. |
| Inbound webhook route | **None registered.** |
| Paystack / any PSP integration | **None.** |
| `SendWhatsAppMessage`, `OutboundMessage` | Exists, used by `CorridorNotifier`. |
| `rate_reservations` | Exists. Catches a declined-lane enquiry before a price exists. |

So the messaging side is outbound-only and the payment side does not exist.

## The three states a booking moves through

Everything else is detail. The shape is:

```
QUOTED ──customer accepts──▶ PAYMENT_PENDING ──PSP confirms──▶ BOOKED
   │                              │
   │                              └──expires / declined──▶ EXPIRED
   └──no response (7 days)──▶ ABANDONED
```

The states that matter are the two on the left, because they are the ones a
naive build gets wrong:

- **`ABANDONED` is not a failure state.** Most senders will get a quote and
  never reply. Recording that is how we learn whether the price is the problem.
- **`EXPIRED` is not `ABANDONED`.** A payment link that timed out is a different
  event from a person who went quiet, and conflating them will make the
  abandonment rate meaningless.

## Webhooks

Two inbound endpoints, both unauthenticated to the customer and therefore both
must verify on the request itself.

### `POST /webhooks/whatsapp`

Twilio posts inbound messages here. The handler must:

1. Verify the Twilio signature on the raw request body — **before** parsing.
   A webhook that trusts its own JSON is an endpoint anyone on the internet can
   post to.
2. Match the message to a booking by the reference we embedded in the link, not
   by the sender's phone number. A sender may message from a different number
   than the one they quoted from.
3. Ignore anything that is not a reply to a booking we actually hold. Unknown
   senders get a polite fallback, not an error page and not silence.

### `POST /webhooks/paystack`

Paystack posts `charge.success` here. The handler must:

1. Verify the HMAC signature on the raw body using the Paystack secret key.
2. **Be idempotent.** Payment webhooks are retried, and are *guaranteed* to
   arrive at least once. Keying on the transaction reference — not on the
   webhook id — makes a replay a no-op instead of a second booking.
3. Record the payment **before** booking, in the same transaction. A confirmed
   payment with no booking is the worst state this system can be in, and it
   should be impossible rather than unlikely.
4. **Reconcile against the PSP, not against WhatsApp.** Meta does not
   reconcile payments and neither will this system; Paystack's record is the
   source of truth. A reconciliation job compares our bookings to Paystack's
   transactions daily and flags the difference.

## What auto-books on confirmation

Only when all of these hold. Any one missing means the booking waits for a
human, and that is a normal outcome rather than a failure:

- The rate row that quoted it still exists **and** is still effective today.
- The quoted total still equals the recomputed total for those inputs.
- The customer has given a destination address.

That third one is why this is not a smaller job than it looks: **there is no
address on this path yet.** SP-2 is sender identity and addresses. This design
does not replace it, it depends on it. What this adds is the *payment*, not the
*identity*.

The reason the first two are checked rather than trusted is that a quote is a
promise made at a moment, and prices move. A customer who accepts a rate from
three weeks ago has not agreed to today's rate. Re-pricing at booking time and
refusing when it has moved is honest; booking the old number is how a rate card
becomes a liability.

## Open decisions — these are forks, not tasks

**1. Twilio or a payments-capable BSP?**
Twilio's WhatsApp runs on the Cloud API and would handle both webhooks. It does
not do payments, so Paystack sits alongside either way. The alternative is a BSP
that bundles both, at the cost of vendor lock-in and likely worse MoMo pricing.
**Recommend: keep Twilio, add Paystack.** Two integrations is cheaper than one
migration, and Twilio is already proven here.

**2. Cloud API or coexistence?**
Automation requires the Cloud API. If the number is currently on the WhatsApp
Business App, coexistence **does not sync the catalog to the API** and breaks
payment-link flows. This must be settled before anything is built.

**3. Who holds the funds?**
Paystack settles to a Ghana bank account. That raises the question this spec
deliberately does not answer: whether Opoku takes the sender's money directly,
or whether the parcel is paid for by the recipient in Tema. These are different
businesses with different regulatory exposure, and the second one is
legitimately more complicated.

## Sequencing, and why Paystack goes first

Paystack onboarding requires business registration and approval, **typically days
to weeks**, and is not self-serve. It gates live transactions no matter how fast
the code is written. So:

1. **Start Paystack approval now.** It has the longest lead time and zero
   dependencies.
2. Confirm decisions 1–3 above.
3. Inbound WhatsApp webhook + signature verification. Independent of payments,
   and useful on its own.
4. Booking states + expiry. Pure logic, testable to the second.
5. Payment link generation.
6. `charge.success` handler + reconciliation.

## What this does not fix

**The tariff.** Everything above collects a payment. At present the amount
collected is our corridor cost — `$0.32/kg` and a `$25.00` minimum taken straight
from the forwarder's invoice, labelled `(DEMO TARIFF)` in the seeder so nobody
mistakes it for a published price.

A checkout is a machine for taking money. Building it before the price is real
means building it to charge customers our costs, faster. The tariff is a
one-line change in `LogisticsRateSeeder` and blocks everything else on this page.

**Demand.** There has not been a single enquiry from a stranger. Every state in
the machine above is currently hypothetical. The WhatsApp handoff already
shipped is the cheapest way to find out whether any of this is worth building,
and it should run for a while before this spec is implemented.