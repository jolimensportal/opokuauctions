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
| Origin zones | **Postcode bands**, not named cities | Newark and Houston are not the same lane; a city-level rate is either too cheap or too dear |
| Unmapped postcode | **No quote, with the reason stated** | The only option that never quotes a rate we have not priced |
| Currency | **On the card, not the row** | One card per currency per version, so a GHS reprice can never touch a USD card |
| Pickup surcharge | **Its own rate row** | A courier bills per stop, not per kilo. A row with a high minimum prices a stop exactly |

## Data model

```
postcode_bands   id, country (us|ca), prefix, zone_id, active

origin_zones     id, country, code, name, active

rate_cards       id, name, currency, created_at
                 — a named grouping of rows in one currency. No dates.

rate_card_rows   id, rate_card_id, origin_zone_id, destination,
                 mode (air|sea), unit (parcel|pallet),
                 intake_method (dropoff|partner_pickup|self_pickup),
                 service_level, divisor,
                 per_kg_cents, per_pallet_cents, min_charge_cents,
                 max_billable_kg,
                 effective_from, effective_to
```

Bands and zones are separate on purpose. A band maps a sender's postcode to an
internal zone; rate rows price zones. Adding a band for a new town is therefore a
data edit, not a reprice — and the two change for entirely different reasons and
on entirely different timescales.

### Postcode normalisation

Postcodes arrive in whatever form a customer types them, and the two countries
format differently:

| Country | Input | Normalised | Rule |
|---|---|---|---|
| US | `07030` | `07030` | ZIP, five digits. ZIP+4 `07030-1234` is truncated to the ZIP |
| US | ` 7030 ` | `07030` | Whitespace stripped, then left-padded to five |
| CA | `m5v 2t6` | `M5V2T6` | Uppercased, spaces and hyphens removed |
| CA | `M5V-2T6` | `M5V2T6` | As above |

A prefix band is matched against the start of the normalised value, so `M5V` is
one band and `M4B` is another. Normalisation is a pure function with its own
tests; a postcode that cannot be normalised is a no-quote, never a guess at the
country.

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

**Decided after building it.** A standard 120×120×150 cm pallet is **1,080 kg
volumetric** at the air divisor of 2000. Because billable weight is
`max(actual, volumetric)`, every pallet below about 1,080 kg priced identically —
a 40 kg pallet and a 900 kg pallet both quoted $345.60 — which left
`per_kg_cents` on a pallet row doing nothing for almost every shipment.

A number that never changes is worse than no number, because an operator will
assume it is doing something. So:

- A **pallet row is priced from `per_pallet_cents` outright.**
- `per_kg_cents` is nullable and NULL on a pallet row.
- A rate row that *does* supply both still works: the calculated per-kg rate
  wins when it exceeds the pallet price. A row can express either shape.

The service still applies the pallet price as a floor over the calculated rate,
so the decision costs nothing and removes the misleading field.

The alternative considered was pricing pallets at the sea divisor of 5000, which
drops the standard footprint to 432 kg so real weight starts mattering sooner.
Rejected: it invents a second rate shape to solve a problem that footprint
pricing already solves, and pallet pricing is genuinely a per-pallet product.

### Rate matching

A quote resolves to exactly one row, in two steps:

**First, the postcode becomes a zone.** Normalise the sender's postcode, then
find the active band for that country whose prefix matches, and take its zone.
A postcode with no band stops here: no quote, naming the postcode, so the sender
can tell us and we can add the band. Falling back to a national rate would
discard the reason for bands; falling back to the nearest band would be a guess
presented as a price.

**Then the zone becomes a row.** Match on:

1. `origin_zone` — resolved above, never typed freehand
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
| The postcode cannot be normalised | We do not know which country it belongs to |
| The postcode matches no active band | That origin is not priced yet, and we say which postcode stopped us |
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

- `PostcodeNormalisationTest` — one test per normalisation rule, plus the
  Canadian and US formats side by side and an input that cannot be normalised.
- `PostcodeBandTest` — a matching prefix resolves, a non-matching one does not,
  a longer prefix beats a shorter one, and an unmapped postcode returns the
  reason rather than a fallback.
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

## Consequences of postcode bands, stated plainly

Bands price tighter, and they cost three things that are easy to forget:

- **Unmapped postcodes are unsellable.** A customer in Ohio gets no price at all
  until a band exists for them. The bands have to be populated from the lanes we
  actually serve, and the first thing operations will want is a way to add one.
- **Postal formats differ.** A US ZIP and a Canadian postal code are not the
  same shape, and `M5V` must not be read as a number.
- **Longest-prefix-wins.** Overlapping bands such as `07` and `070` need a
  defined order or resolution is arbitrary.

## Pickup is priced by the same mechanism

A pickup is not a special case bolted onto a quote. It is another row with
`service_level = pickup`, carrying its own `per_kg_cents` and `min_charge_cents`.

This matters because a partner courier bills **per stop**, and a stop is not a
kilo. A rural Ohio stop costs the forwarder about what a Newark one does, so a
single flat fee must either lose money on rural pickups or overcharge dense
ones. A row with a high minimum prices a stop correctly without a second pricing
engine.

The quote therefore returns a **line per service level** — base freight, then
pickup if requested — rather than a single lump. That also means a customer can
see exactly which part of the price is the stop.

`freight_rates.pickup_cents` on the car lane is untouched. Car pickups stay flat
until the car lane itself migrates, which is a separate task.

## Status

No open questions remain. Ready for an implementation plan.