# Opoku Auctions — vertical slice design

**Date:** 2026-10-16
**Status:** DRAFT — Sections 1-2, Section 2 awaiting approval
**Foundation:** Laravel 13 + Filament 5 (see `2026-10-16-stack-decision-medusa-vs-laravel.md`)

---

## Section 1 — Domain model

The vertical slice this describes: **signup → bid → deposit → admin books a
forwarder → track the container → deliver → settle.**

### 1.1 Money: already proven

`Wallet` and `LedgerEntry` are built and tested (21/21). Not re-specified here.
Carried forward as decided:

- integer minor units, never floats
- two buckets per wallet: `available` and `held`, both `CHECK (>= 0)`
- append-only ledger; balances are a rebuildable cache of it
- ledger types: `DEPOSIT`, `HOLD`, `RELEASE`, `CAPTURE`, `WITHDRAWAL`, `ADJUSTMENT`
- a `HOLD` moves money between buckets and is neutral to the total; a `DEPOSIT`
  raises the total; a `CAPTURE` lowers it

The ledger needs one more type this design adds: **`PAYOUT`**, for money leaving
to a garage, forwarder or consigner. It is a `CAPTURE` against the platform's own
liability account, not a buyer-side movement.

### 1.2 The one new concept that changes the product shape

**Auctioning a vehicle and shipping a vehicle are one transaction, not two.**

The call notes describe consolidation: multiple won cars move in one container.
So a `Lot` does not end at "sold". It ends when the car is in the buyer's hands.

This is the modelling decision everything else hangs off, and it is why the
platform is defensible rather than a copy of Copart with extra steps.

### 1.3 Entities

```
User ──────────────┐
  (buyer)          │ has many
                   ▼
                 Bid ─────────► Lot ─────────► Shipment ─────────► Milestone
                                ▲                 ▲                     ▲
                                │                 │  loaded into        │ recorded by
                          won at auction     ShipmentLot              (API or admin)
                                                (allocation)
```

**User** — a buyer. Email + password. Phone is important and should not be
optional: the Ghana delivery flow runs on it. Country drives currency defaults.

**Lot** — one thing for sale, whatever it is. Vehicle or pallet.

| Field group | Fields |
|---|---|
| Identity | `id`, `title`, `description` |
| Vehicle | `vin`, `year`, `make`, `model`, `trim`, `odometer` |
| Provenance | `condition` (clean_title / salvage / project / parts_only), `damage_primary`, `source` (auction_feed / dealer / private_seller / garage_intake) |
| Place | `node_id` — which city garage holds it |
| Terms | `sale_format`, `currency_code`, `starting_bid`, `reserve_price`, `bid_increment`, `starts_at`, `ends_at` |
| State | `status`, `current_bid`, `highest_bidder_id`, `bid_count`, `extensions` |

**Shipment** — the corridor. Many won lots, one container.

`forwarder_id`, `container_no` (ISO 6346), `booking_ref`, `bill_of_lading`,
`seaport_origin`, `seaport_destination`, `status`, `eta`, `actual_departure`,
`actual_arrival`, `tracking_source` (shipsgo / traqo / manual)

Milestones: `BOOKING → LOADED → SAILING → ARRIVED → DISCHARGED → GATE_OUT →
CLEARED → DELIVERED`. Fed from a tracking API, but **every milestone is
admin-overridable** because API feeds are late and wrong, and a Ghanaian buyer
being told the wrong arrival date is a support incident.

**ShipmentLot** — the join. How many cars from which lot ride in which
container. Needed because one lot can be split (rare) and one container holds
many lots.

**Node** — a city garage or collection point. `city`, `address`, `contact_name`,
`contact_phone`, `active`. Toronto, New York, Detroit, Milwaukee to start.
This is the "garage network" from the call, and it is also where
`GarageIntake` lots come from.

**Pallet** — handled as a `Lot` with `sale_format = FIXED_PRICE`, not as a
separate entity. A pallet has no VIN, no odometer, no reserve and no bidding
increment, and modelling it separately would fork the money path for no gain.

### 1.4 `sale_format` — the enum that keeps this honest

```
AUCTION      timed bidding with reserve and anti-snipe   (vehicles)
FIXED_PRICE  buyer-takes-it at an asking price           (liquidation pallets)
```

Two formats, one entity, one wallet, one ledger. "Liquidation" is a *reason to
sell*, not a different mechanism. The call notes mention liquidation as a
feature; it does not need its own machinery.

### 1.5 Forwarders and garages are records, not users — deliberately

Your call was **buyers + admin only**. Forwarders and garages are offline,
recorded by admin.

That is recorded in the model without painting us into a corner:

- `Node` and `Forwarder` are plain records with contact details
- admin books shipments *on their behalf*; they never log in
- when (if) they do get accounts, it is an added `users.role`, not a migration
- the `Shipment` model does not care who typed it — `booked_by_user_id` records
  the admin, and a future forwarder user would simply be a different actor

### 1.6 Lot lifecycle

```
                    ┌──────────────────────────────┐
                    ▼                              │
  UPCOMING ──► LIVE ──► SOLD ──► (allocated to a  │
     │          │                   Shipment)     │
     │          └──► RESERVE_NOT_MET              │
     │                                             │
     └────────► CANCELLED ─────────────────────────┘
```

`SOLD` is not the end state. A sold lot that has not been allocated to a
shipment is *incomplete*, and the admin console should show that as work
outstanding. This is the single most useful operational view in the whole
platform: **money taken, car not yet moving.**

---

## Decisions taken (2026-10-16)

| # | Decision | Answer |
|---|---|---|
| D1 | Buyer payment timing | Deposit at auction close; balance before the car is loaded |
| D2 | Currency | Quoted in the buyer's currency, settled in USD |
| D3 | Pallet format | Fixed price, buy-it-now |
| D4 | Garage-intake seller | The platform buys from the garage, then resells |

---

## Section 2 — Money flow

The spine of the product. One buyer, one vehicle, start to finish.

### 2.1 The currency model

D2 has a consequence that must be explicit, because it is where this business
actually makes or loses money.

**Lots are priced in USD. Buyers are quoted in their own currency. The platform
settles in USD.**

So the platform does not simply hold USD. It holds **GHS and CAD** collected from
buyers and converts them to USD to pay garages and forwarders. That creates a
real, unavoidable position:

> A Toronto buyer pays a CAD deposit on Monday. The car loads in three weeks. If
> CAD weakens against USD in those three weeks, the platform eats the difference.

There is no arrangement that avoids this if the buyer is quoted in their own
currency. The only question is whether it is managed or discovered later.

**Mitigation: the rate locks at deposit.** Once a buyer pays the deposit, the
locked rate applies to the balance too. That means:

- the buyer's total obligation is fixed the moment they commit — they are not
  exposed to the currency moving against them
- the platform knowingly carries the FX position from deposit to settlement
- the rate shown at bid time is the rate they get, and it is stored on the lot

This is the fair version of the arrangement and it is the one worth building.
The alternative — re-quoting the balance at current rates — is a bait-and-switch
that will cost you every repeat buyer you have.

**New ledger type: `CONVERT`.** Money moving between currencies is two entries,
never one:

- negative `CONVERT` on the source wallet (e.g. GHS)
- positive `CONVERT` on the destination wallet (USD)
- both share a `conversion_id`, and both record the rate used

A conversion is not a profit or a loss. It is a re-denomination. The platform's
actual gain appears as `CAPTURE` (the spread on the vehicle) and `WITHDRAWAL`
(its own margin), never hidden inside an FX line.

### 2.2 New entities

**FxQuote** — a rate, with an expiry.

| Field | Notes |
|---|---|
| `id`, `base_currency` (`usd`) | the settlement currency |
| `quote_currency` (`ghs`, `cad`) | the buyer's currency |
| `rate` | integer, rate × 10⁸, to avoid float drift |
| `as_of`, `expires_at` | a rate that has expired cannot be used |
| `source` | manual, paystack, ecb, etc. |

Rates are stored as integers for the same reason balances are: a float rate is
where rounding arguments start.

**LotDepositTerms** — per-lot commercial terms, so they can change per lot without
a redeploy:

`deposit_bps` (basis points — 2000 = 20%), `balance_due_before_load` (bool,
default true)

### 2.3 The flow

Each step names the ledger entries it writes. This is the part to review hardest.

**1. Buyer opens a wallet in their own currency.** No balance. `GHS` or `CAD`.

**2. Buyer deposits.** `DEPOSIT` into their wallet, called only from a verified
Paystack / Stripe webhook. Verified means: signature checked, amount and currency
matched, replayed event ids ignored.

**3. Buyer bids.** The bid stores three numbers, not one:

- `amount_usd` — the authoritative amount, in USD minor units
- `quoted_amount` — what the buyer sees, in their currency minor units
- `fx_rate_used` — the rate at quote time, so the bid can be audited later

**4. Auction closes.** Winner's deposit moves available → held.
`HOLD` on the winner, `RELEASE` on everyone else. (Already proven in the 21/21
suite.)

**5. Balance falls due before loading.** `HOLD` at the **locked** rate from step 3.
The buyer now owes a fixed number.

**6. Admin allocates the lot to a shipment.** No money moves. But the lot enters
the outstanding-work view from Section 1.

**7. Admin books the forwarder.** Still no money moves.

**8. Loading is gated.** **The car does not physically load until the balance is
paid.** Enforced in the admin, not merely advised: the `LOADED` milestone cannot
be recorded while the lot is unpaid. This one rule prevents an entire class of
loss — car loaded, buyer ghosts, you own a depreciating container of vehicles.

**9. Shipment clears customs at Tema.** `GATE_OUT` then `CLEARED`.

**10. Delivered → capture and settle.** This is where the platform earns:

- the buyer's held funds are `CAPTURE`d (they leave the buyer's wallet)
- the equivalent USD is paid to the garage or consigner: `PAYOUT`
- the difference between what the buyer paid and what the seller received is the
  platform's margin, and it is already separated from FX by the two-entry
  `CONVERT`

**11. Disputes.** A lot can be disputed after delivery and before payout. Disputed
funds stay held. Payout is blocked until an admin releases them. Nothing in this
flow pays out on a timer.

### 2.4 What this section adds to the money core

| Addition | Why |
|---|---|
| `FxQuote` | Rates need an identity, an expiry and a source, not a config value |
| `LotDepositTerms` | Commercial terms vary per lot; basis points, not floats |
| `CONVERT` ledger type | FX is re-denomination, never profit |
| `PAYOUT` ledger type | Money leaving to a garage, forwarder or consigner |
| `conversion_id` | Ties the two halves of a conversion together |
| Rate locked at deposit | Fixes the buyer's obligation; the platform carries the position knowingly |

The proven 21 checks are unchanged by this section. New tests needed before this
ships: a buyer's full obligation must not change between deposit and balance, and
a conversion must conserve value across both wallets.

---

## Section 3 — Shipment lifecycle and milestones

This is the section that makes the product a corridor rather than an auction
site. Everything so far could have been built by a Copart clone. This part
cannot.

### 3.1 Milestones are recorded twice

Every milestone exists in two places:

- **Observed** — from a tracking API (ShipsGo / Traqo), by container number
- **Recorded** — by an admin, by hand

`ShipmentMilestone` keeps both, plus which one the displayed value came from
(`source: api | admin`).

**Rule: admin always wins.** An admin override is stored and is never
overwritten by a later API poll. This matters more than it sounds — tracking
feeds are late, wrong, and occasionally silent for weeks. Telling a Ghanaian
buyer in Accra that their car arrives on the 14th when it is still on the water
is not a data quality issue, it is a support incident and a loss of trust in
everything else you say.

The API poll never *creates* a milestone the admin has already recorded. It
fills gaps only.

### 3.2 States

```
BOOKING → LOADED → SAILING → ARRIVED → DISCHARGED → GATE_OUT → CLEARED → DELIVERED
   │         │
   │         └── blocked until the lot's balance is PAID (see 2.3 step 8)
   │
   └── CANCELLED  (booking fell through; lots return to UNALLOCATED)

DELIVERED → DISPUTED → RELEASED   (funds stay held until an admin releases)
```

`CLEARED` is the Tema customs milestone and it is the one buyers actually care
about, so it gets its own notification rather than being buried in a list.

### 3.3 Who can move a milestone

Only admin, for now — forwarders are records, not users (Section 1.5). But the
model does not hard-code that: every milestone row stores `recorded_by_user_id`,
so when forwarders get accounts the column is already right.

### 3.4 Allocation

`ShipmentLot` (lot_id, shipment_id, quantity) links won lots to a container.

A lot is `UNALLOCATED` once sold and until it is in a shipment. The admin console
shows **sold-but-unallocated** as the primary work queue, because that is money
taken with a car not moving — the most expensive state to sit in.

Consolidation is the whole point: five buyers' cars from Detroit go in one
container, one booking, one freight bill. The allocation table is what makes
that possible.

### 3.5 Notifications

Every milestone change queues a notification to the buyer on **WhatsApp**, not
email. The corridor already runs on WhatsApp; email is how you get ignored.

`CLEARED` and any delay get priority. Notification failures never block the
milestone — the shipment moved, and telling the buyer is a separate concern that
can be retried.

### 3.6 Exceptions worth designing for now

| Exception | Behaviour |
|---|---|
| **Rollover** — container misses the vessel | Milestone goes `SAILING → BOOKING` (rolled back), buyer notified, funds stay held |
| **Partial delivery** — some cars clear, some held at customs | Lot-level status, not shipment-level. One car's problem must not stall the other four |
| **Damage in transit** | Dispute opens, payout blocked, evidence photos attached to the lot |
| **Loss / write-off** | Admin records it, captures the buyer's balance as a loss, and the ledger shows exactly where that money went |

The loss case is why disputes exist as a state rather than a flag. Money that
leaves the platform must always have a ledger row saying why, and `CAPTURE`
against a loss account is the honest way to write that.

### 3.7 What this section adds

- `Shipment`, `ShipmentMilestone`, `ShipmentLot` entities
- milestone source precedence (admin > API)
- the payment gate on `LOADED`, enforced not advised
- lot-level status inside a shipment, for partial delivery
- WhatsApp notification queue, non-blocking
- an explicit loss/capture path so no money ever disappears silently

**Still not designed:** admin console layout, WhatsApp provider choice, and the
customs-clearance document checklist. Those are Section 4.

---

## Section 4 — Admin console, WhatsApp, and customs clearance

The last section. Everything here is about the operator's day, not the buyer's.

### 4.1 The console is organised around money, not around entities

A typical CRUD admin lists `lots`, `bids`, `wallets`. That is a data model, not
a workflow. This console is organised by what the operator is trying to finish:

| Queue | The question it answers | Why it matters |
|---|---|---|
| **Needs money** | Which sold lots are unpaid? | Balance due before load. Blocks loading. |
| **Unallocated** | Which paid cars have no container? | Money in, car not moving — most expensive idle state |
| **Booking** | Which shipments need a forwarder and a booking? | The manual step before anything sails |
| **In transit** | Where is each container, and is any milestone stale? | Buyer questions start here |
| **At Tema** | Which containers are clearing? | The milestone buyers care about |
| **Disputed** | Whose money is held pending a decision? | Blocks payout; never auto-resolves |
| **Reconcile** | Do any wallets disagree with their ledger? | The daily integrity check |

The settlement console already built in the spike is the last row of this table,
and it works.

### 4.2 Two admin roles, not one

| Role | Can do |
|---|---|
| **Ops** | Book shipments, record milestones, allocate lots, message buyers |
| **Owner** | Everything Ops can, plus release disputed funds, adjust balances, force-close an auction |

The split exists because of one rule: **only an Owner can move money out of a
disputed state.** Ops can create every operational problem and cannot resolve a
financial one alone. With a solo dev this costs nothing to set up now and is
close to impossible to retrofit later, because it means auditing who released
what.

### 4.3 WhatsApp as the notification channel

The corridor already runs on WhatsApp. Email is how you get ignored.

- **Provider:** Twilio WhatsApp to start — Meta's Cloud API needs a verified
  business and an approved template per message type, which is a multi-day
  process and not something to block a launch on.
- **Templates:** GHS amounts and dates change per message, so these are
  *session* messages (free, only inside a 24h customer service window), not
  *template* messages. Initiation messages outside the window need templates
  approved in advance — plan for that, it is the real integration work.
- **Queue, not synchronous:** milestone changes enqueue a notification job.
  **A failed notification never blocks a milestone.** The container moved; telling
  the buyer is a separate concern that retries.
- **Delivery receipts are tracked** so "did they actually get it" is answerable.

Every buyer gets a WhatsApp number at signup and it is required, not optional —
the Ghana delivery flow runs on it.

### 4.4 Tema customs checklist

`ClearanceTask` — a per-shipment checklist the Ghana team ticks off. Each task
has `type`, `status`, `notes`, `completed_at`, `completed_by`.

The first cut, based on what a container of used vehicles actually needs:

1. Bill of lading received from forwarder
2. Import declaration filed (GCNet)
3. GHAPHA / FDA import permit cleared
4. Duty assessed and paid (receipt attached)
5. VAT paid (receipt attached)
6. Port charges and handling settled
7. Examination / release order obtained
8. **Gate-out permit collected**
9. Gate-out executed at Tema port

**Receipts are attachments, not checkboxes.** A tick with no receipt is not
evidence, and customs disputes are settled with documents. Task 9 is deliberately
last because gate-out is the moment the buyer can actually collect.

Money-relevant tasks (4, 5, 6) post their cost to the shipment so the platform
knows the true landed cost per vehicle — which is the number the whole landed-cost
promise rests on.

### 4.5 Audit log

Every admin action that touches money or state is written to `AuditLog`:
`actor`, `action`, `entity_type`, `entity_id`, `before`, `after`, `ip`, `at`.

Not optional. A dispute with no audit trail is a dispute you lose.

### 4.6 What this section adds

- the seven-queue console structure, organised by work rather than by table
- two admin roles, with fund release restricted to Owner
- WhatsApp via Twilio, queued, non-blocking, delivery-tracked
- `ClearanceTask` with receipt attachments and cost posting
- `AuditLog` on every money and state change

---

## Design complete — what to build, in order

1. **Auth + roles** (buyer, ops, owner) — nothing else is safe without it
2. **Shipments and milestones**, with the payment gate on `LOADED`
3. **The seven-queue console** on Filament
4. **WhatsApp notifications**, queued
5. **`ClearanceTask`** and landed-cost tracking
6. Payments: Paystack for Ghana, Stripe/Interac for Canada
7. The public buyer-facing storefront

The proven 21-check money core underpins all of it and stays unchanged.

**Deliberately deferred, and worth saying so out loud:** multi-vendor seller
self-service, real-time bidding over websockets, and any marketplace features.
None of them are needed for the corridor to work, and each one would cost focus
that the slice above needs more.
