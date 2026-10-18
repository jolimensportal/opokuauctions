# Logistics Rate Card — Design

**Date:** 2026-10-18
**Status:** Draft for review
**Branch:** `multi-kind-lots-and-ops-console`

## Why this exists

Opoku moves vehicles it sold, on one lane, priced per kilo for cars. There is no
concept anywhere in the codebase of a customer who is not a buyer: no `shipper`,
no `consignor`, no `sender`, no `parcel`, no `origin_address`, no Canada.

The business wants a logistics portal where anyone in the USA or Canada can send
goods they did not buy from us. That is a new product, not a feature, and it
decomposes into five sub-projects:

| Sub-project | Covers |
|---|---|
| **1 — this spec** | The rate card and the quoting engine |
| 2 | Sender identity, addresses, guest vs registered booking |
| 3 | Booking, labels, intake, warehouse receipt |
| 4 | Customs: HS codes, duty and VAT on third-party goods |
| 5 | Consolidation, tracking, claims, payment collection |

Sub-project 1 is deliberately first: it is pure logic with no external
dependencies, it is testable to the cent, and nothing downstream can be built
without a way to price a shipment.

## Decisions already taken

| Question | Decision | Why |
|---|---|---|
| Who owes import duty | **Opoku (DDP)** | Matches the site's existing landed-cost promise; the buyer opens one parcel with no bill on arrival |
| Intake methods | **Model three, launch two** | All three exist in the schema from day one; drop-off and partner-courier pickup go live, self-operated pickup becomes a rate change rather than a rewrite |
| What is priced | **Billable weight, parcel and pallet** | A 2 kg box of screws and a 2 kg box of pillows cost the same to move |
| Pallet pricing | **Per pallet, with a floor** | A half-empty pallet costs what a full one costs to handle |
| Rate card structure | **Versioned, immutable rows** | A rate change is an insert, so every historical shipment keeps the rate that applied when it shipped |

## Data model

```
origin_zones     id, country, code (US-NJ, US-TX, CA-ON, CA-BC), name, active

rate_cards       id, name, currency, created_at
                 — a named grouping of rows. Deliberately carries no dates.

rate_card_rows   id, rate_card_id, origin_zone_id, destination,
                 mode (air|sea), unit (parcel|pallet),
                 intake_method (dropoff|partner_pickup|self_pickup),
                 service_level, divisor,
                 per_kg_cents, per_pallet_cents, min_charge_cents,
                 max_billable_kg,
                 effective_from, effective_to
```

**One change from Section 2.** The effective window moved from `rate_cards` onto
`rate_card_rows`. Two tables holding "when does this price apply" is two sources
of truth, and rows genuinely need independent windows — a zone added in June
should not force every other zone's rates to be re-issued. `rate_cards` becomes a
pure grouping, which is all it ever was.

**The key constraint: rows are never updated.** `effective_to` is nullable and
stays open until a successor is inserted. This is what makes a quote from March
explainable in October.

## The quoting algorithm

All integer arithmetic. No floats anywhere on the money path, for the same
reason `QuoteService` avoids them: `1800 × 0.32` is `576.0000000001` in floating
point, a cent off a price a customer is about to pay.

```
actual_g     = weight_kg × 1000
volumetric_g = round_half_up((L × W × H × 1000) ÷ divisor)
billable_g   = max(actual_g, volumetric_g)
freight      = max(min_charge_cents, round_half_up(billable_g × per_kg_cents ÷ 1000))
```

`divisor` lives on the rate row, not in code: 2000 for air, 5000 for sea.
Hardcoding it in the engine means a lane change is a deploy.

Dimensions are whole centimetres. A dimension of `12.5 cm` is stored `13`. Air
freight is quoted on whole-centimetre boxes; sub-centimetre precision is an
invented number wearing a decimal point.

### Pallet pricing

`per_pallet_cents` is the floor for a pallet and is `NULL` for a parcel. A pallet
row charges:

```
max(per_pallet_cents, round_half_up(billable_g × per_kg_cents ÷ 1000))
```

which is the freight formula plus the pallet floor. A half-empty pallet costs
what a full one costs to handle, so a customer shipping one small box on a
pallet does not get away with $13.

### Rate matching

A quote resolves to exactly one row, matched on:

1. `origin_zone` — derived from the sender's postcode, never typed freehand
2. `destination` — `Tema` for now
3. `mode`, `unit`, `intake_method`, `service_level`
4. the effective window contains today

If more than one row matches, the **most specific wins** — the one with a
service level, over the one without. Zero matches is a no-quote, never a
fallback to a neighbouring zone. Quoting Newark at Detroit's rate is how a
company loses money on a lane it never priced.

## No-quote states

Three, and each is a refusal to invent rather than an error:

| State | Why there is no quote |
|---|---|
| A parcel has no dimensions | Air freight is priced on volume. Without them any figure is invented |
| No row matches the zone, unit, intake and window | That lane is not priced |
| Every matching row is outside its window | The rate we held has expired, and repricing under it is not ours to do |

A no-quote returns the *reason*, not a null. "We cannot price a 3 kg parcel from
Newark on partner pickup" is actionable; a blank card is not.

## Reproducibility

Every quote records the `rate_card_row_id` it used, plus the inputs and the
resulting cents. Re-quoting the same shipment on the same day reproduces the
same figure from the stored row, and the row is immutable so it stays
reproducible forever.

This is the same principle the operations console is built on: for a business
whose pitch is *trust us with your goods*, a price nobody can explain is the
worst possible defect.

## Error handling

- **A missing rate row never falls back.** No nearest-zone, no last-known rate.
- **A zero weight and zero dimensions together is a no-quote**, not a free
  shipment. A customer who has told us nothing gets no quote.
- **Negative dimensions or weight are rejected at validation**, not silently
  coerced to zero. A negative volume would produce a negative billable weight
  and a credit.
- **`max_billable_kg`**, when set, is a hard ceiling: the quote returns the
  ceiling and says so rather than quietly pricing a shipment the forwarder will
  refuse.

## Testing

- `RateRowResolutionTest` — one test per no-quote state, plus the
  most-specific-wins case and the expired-window case.
- `BillableWeightTest` — volumetric beats actual, actual beats volumetric, the
  divisor changes the answer, whole-centimetre rounding is half-up.
- `ParcelQuoteTest` — the per-kg formula, the minimum floor, the ceiling.
- `PalletQuoteTest` — the pallet floor binds on a light pallet and does not
  override real weight-based freight on a heavy one.
- **A reconciliation test:** for every rate row, the sum of the shares equals the
  row's own figure to the cent. A rate card that cannot reconcile is not a rate
  card.
- A reproducibility test: quote, then insert a successor row, then re-quote the
  stored shipment — the figure must not move.

## Out of scope

Shipment identity and booking (SP-2), labels and intake (SP-3), customs and HS
codes (SP-4), consolidation, tracking and payment (SP-5). Also untouched:
`freight_rates` and `QuoteService`, which still price cars on the old lane and
have 37 tests pinning that behaviour. Migrating car freight onto the new rate
card is its own task, and doing both at once makes neither verifiable.

## Open questions

1. **Zone granularity.** Four zones (US-NJ, US-TX, CA-ON, CA-BC) to start, or
   postcode-level bands from day one? Postcode bands price tighter but need a
   postcode dataset and a rule for unmapped areas.
2. **Currency.** Rates are per-card. Should a card be single-currency with a
   separate card per currency, or does a row carry its own currency?
3. **Pickup surcharge.** Task 4 kept a flat per-lot pickup fee. Should a parcel
   pickup be a flat fee or a rate row of its own, given the partner courier
   will bill per stop?