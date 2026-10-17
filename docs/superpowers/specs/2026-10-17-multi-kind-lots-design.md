# Multi-kind lots — cars, pallets and garage clearances

**Date:** 2026-10-17
**Status:** approved, ready to plan
**Supersedes:** nothing. Extends `2026-10-16-vertical-slice-design.md`.

---

## The problem

The platform is called Opoku Auctions, not Opoku Autos. "Auction" was chosen because
auction is the mechanism behind cars, liquidation, pallets, garage inventory and
foreclosure sales. Today it sells cars and nothing else.

The homepage already advertises four lot types. Three of them do not exist, and all
three link to the same vehicles-only board — a buyer clicking "Pallets" gets a page
of cars. The site is making four promises and keeping one.

The blocker is the schema. `lot` carries `year`, `make` and `model` as NOT NULL, and a
pallet has no make or model. Filling those with `N/A` would pollute exactly the columns
the storefront groups and filters by.

Verified against `2026_10_15_200000_create_corridor_tables.php`:

```php
$table->string('vin')->nullable();
$table->integer('year');          // NOT NULL
$table->string('make');           // NOT NULL
$table->string('model');          // NOT NULL
$table->string('trim')->nullable();
$table->string('city');           // NOT NULL
$table->string('condition')->default('unknown');
$table->integer('odometer')->nullable();
$table->string('damage_primary')->nullable();
```

Two things worth knowing before reading further. `city` is declared inside the vehicle
block but is really the origin yard, which every kind has — it stays on `lot`. And
`condition` defaults to `'unknown'`, which is not one of the four codes the storefront
offers, so it is a fifth state with no label today.

## What exists today

- 191 tests passing, 897 assertions. 2 known-red (see Testing).
- Money core: wallet, hold/capture/release, an integer-cent ledger, idempotency.
- Auction: timed bids, increments, reserve, anti-snipe extension, finalisation.
- Corridor: shipment containers, allocation, the paid-before-loading gate, clearance
  tasks, landed-cost reconciliation.
- Buyer storefront: homepage, board, lot page, account, FX display switch.
- Freight quote (new, this week): weight-based, per-lane rate, minimum charge.

## Decisions taken

Each of these was decided in conversation, not assumed.

1. **Everything is bid on as its own listing.** One car, one pallet, one bed frame, one
   garage — each has its own photos, price, bid, and close time. Nothing is glued into an
   unbreakable bundle against the buyer's wishes.
2. **Garage sale goods are sold individually.** A headlamp at 2 cedis, a bed frame at 30
   cedis. They are real lots with real bids, not decoration inside a garage lot.
3. **A whole garage can *also* be listed as one lot** when that is the easier sale that
   week. Both are supported; neither is forced.
4. **Pallets and liquidation are the bulk trade** — whole pallets, bundles, truckloads.
5. **Foreclosure is provenance, not a category.** The site's own copy describes it as
   *"vehicles repossessed in North America"* — a car with a different history, not a
   different kind of thing.
6. **Freight is never charged per item.** A 2-cedis headlamp cannot absorb a $250
   minimum consignment charge, and nobody is buying a lamp to pay for a container. Freight
   is shared across the shipment and allocated per lot, so it scales down to something
   sane at small values instead of being absurd. This reworks the quote, which currently
   assumes one lot is one consignment.
7. **Pallet homepage copy gets rewritten.** It currently says pallets are *"at a fixed
   price. No bidding and no waiting for a lot to close."* Pallets are timed auctions, so
   that is wrong.
8. **Scope of this pass: schema + storefront + admin.** Pallets get built after, not in
   the same spec.

### On freight

Decision 6 reopens the freight work finished this week. `QuoteService` currently does two
things that only make sense when a car is the whole consignment:

```php
// line 75 — freight floored at the forwarder's minimum consignment charge
$freight = max($rawFreight, (int) $rate->min_charge_cents);

// line 78 — insurance is FLAT, regardless of what it insures
$insurance = (int) $rate->insurance_cents;
```

So a 2-cedis headlamp quotes **$250 freight plus $107 flat insurance — $357 to move
something worth 13 cents.** Both halves are wrong at that price, not just the floor, which
is why the rework covers the whole per-lot basis rather than the one `max()` call.

The replacement allocates container cost across what actually ships in it. Same integer
cents, same ledger, no new money concepts — the floor stops being a per-lot constant and
becomes a shipment-level cost divided among the lots inside. A headlamp's share becomes a
handful of cents, which is honest rather than absurd.

Insurance needs the same treatment: a flat $107 per car is not an insurance rate, it is a
car rate wearing insurance's name. Whether it becomes genuinely proportional, or a
per-shipment cost allocated the same way freight is, is the open sub-question — both keep
it in integer cents.

## Architecture

### One `lot` table holds everything a bid needs

The money and auction path keeps touching exactly one table. That is the property that
made the Laravel-over-Medusa decision winnable, and it is the thing not to give up.

New on `lot`:

| column | notes |
|---|---|
| `kind` | `vehicle` \| `goods`, CHECK-constrained. Two shapes, because a car has a VIN and a pallet does not. |
| `provenance` | `garage_sale` \| `estate` \| `repo` \| `salvage_auction` \| `liquidation` \| `overstock` \| `trade_in` |
| `title` | NOT NULL. The buyer's name for the lot. |
| `description` | nullable text. Where a garage item's story goes. |

A single garage item is just a `goods` lot with `provenance = garage_sale`. A whole
garage lot is the same shape with a higher `starting_bid`. **No new kind is needed for
the two garage modes** — that is the point of decision 3.
| `description` | nullable text. Bulk lots have nothing today. |

Changed on `lot`:

| column | change |
|---|---|
| `curb_weight_kg` | renamed `weight_kg`. "Curb" is vehicle vocabulary; a pallet weighs too. Stays `decimal(7,1)`, so one decimal place of a kilo — fine for a pallet, deliberately coarse for a chair. |
| `condition` | stays a code, but the vocabulary is now per-kind |

Unchanged, deliberately — the money core in full:

`currency_code`, `starting_bid`, `reserve_price`, `bid_increment`, `current_bid`,
`highest_bidder_id`, `deposit_bps`, `balance_settled_at`, `balance_due_usd`,
`fx_quote_id`, `starts_at`, `ends_at`, `status`, `bid_count`, `extensions`, `photos`.

`city` also stays, per the note above.

### `title` deletes duplication

The lot name is currently built inline in **12 places across 8 Blade files**:

| file | occurrences | live? |
|---|---|---|
| `shop/partials/lot-row.blade.php` | 2 | yes |
| `shop/partials/lot-tile.blade.php` | 2 | yes |
| `shop/lots/show.blade.php` | 2 | yes |
| `filament/pages/corridor-dashboard.blade.php` | 2 | yes |
| `shop/account.blade.php` | 1 | yes |
| `shop/order-show.blade.php` | 1 | yes |
| `shop/landing-1b.blade.php` | 2 | **no — orphaned** |

all of the form `{{ $lot->year }} {{ $lot->make }} {{ $lot->model }}`.

`landing-1b.blade.php` is referenced by no route, controller or view — `home` returns
`shop.home`. It is a dead alternate landing page and it should be deleted rather than
migrated, which retires two of the twelve for free. **That leaves 10 live sites across
6 live files.**

**Four are `alt="..."` image attributes, six are visible titles.** One stored `title`,
backfilled from year+make+model on migration, collapses the six visible ones. The four
alt-text sites should arguably keep describing the image rather than echoing the title,
so that is a judgement call in review rather than a forced mechanical replacement.

A pallet has no make or model, so this is not only a tidiness problem — these sites
render wrong for every non-vehicle lot.

### Detail tables, one row per lot, only where needed

**`vehicle_detail`** — `lot_id`, `vin`, `year`, `make`, `model`, `trim`, `odometer`,
`damage_primary`. These eight columns move off `lot`.

**`goods_detail`** — `lot_id`, `unit_label`, `units`, `grade`, `manifest`,
`manifest_count`, `length_cm`, `width_cm`, `height_cm`.

Dimensions get columns but **do not feed the quote**. The quote is weight-based and
volumetric freight is a separate decision, deliberately not smuggled in here.

`unit_label` is a short free-text string ("Pallet", "Bed frame", "Garage sale", "Cart")
and carries the weight of distinguishing a bulk lot from a single item. For a single
garage item the remaining bulk columns are simply null: no manifest, no carton count, no
dimensions. Nothing forces a headlamp to declare a grade code it does not have.

One detail table serves both because the *shape* is identical — a thing with a weight and a
grade. Only how much of the bulk metadata is filled in differs.

### Condition becomes data, not a hardcoded array

`ShopController::conditions()` (`app/Http/Controllers/Shop/ShopController.php:54`)
currently returns a PHP array of four vehicle labels, each with a sentence of sales copy
attached. It becomes a query against `condition_definition`
(`kind`, `code`, `label`, `blurb`, `sort`).

| kind | codes |
|---|---|
| `vehicle` | `clean_title` · `salvage` · `project` · `parts_only` — unchanged |
| `goods` | `grade_a` · `grade_b` · `returns` · `damaged` · `mixed` |

One vocabulary covers both bulk and single garage items: a headlamp and a pallet are both
`grade_a` or `grade_b`. Splitting them into separate vocabularies would add filters buyers
do not ask for.

The condition explainer on the homepage then edits without a deploy.

`unknown` needs a home. It is the current column default and the storefront does not
offer it, so either it becomes a seeded fifth code with honest copy, or the migration
normalises existing `unknown` rows to `grade_mixed` / `project`. Either is defensible;
picking one is a review decision.

### What each homepage tile becomes

| tile | kind | provenance | detail |
|---|---|---|---|
| Vehicles | `vehicle` | `salvage_auction` | vehicle_detail |
| Pallets | `goods` | `overstock` | goods_detail |
| Garage sale | `goods` | `garage_sale` | goods_detail |
| Foreclosure | `vehicle` | `repo` | vehicle_detail |

An empty count on a tile is honest. A tile that quietly links to cars is not.

**Garage sale tiles are price-ordered, not countdown-ordered.** A board that sorts a
2-cedis headlamp and a 30-cedis bed frame by closing soon reads as noise. Ascending price
puts the impulse buys where a browsing buyer will find them.

## Migration

One migration, in this order, with no transitional state where two tables both claim to
hold the make:

1. `alter table lot` — add `kind`, `provenance`, `description`; rename
   `curb_weight_kg` → `weight_kg`.
2. Create `vehicle_detail`, `goods_detail`, `condition_definition`.
3. Add `lot.title` as nullable. Backfill: `update lot set title = year || ' ' || make ||
   ' ' || model`.
4. `insert into vehicle_detail select ... from lot where kind = 'vehicle'`.
5. `alter table lot` — drop the eight vehicle columns, set `title` NOT NULL.
6. Seed `condition_definition`, backfilling the four existing vehicle codes so no lot
   loses its condition.

CHECK constraints in raw DDL, matching the existing `chk_wallet_*` convention: `kind`
and `provenance` domain checks, and no negative money.

## Storefront

- **Homepage** — the four tiles become real category links, and the pallet copy is
  rewritten to match a timed auction. The lot types section stops being decoration.
- **Board** — one list, mixed kinds, same row and same bid button. A `kind` filter
  appears; `make`, `year` and `mileage` facets only appear on `vehicle`.
- **Lot page** — the countdown, deposit and freight card are the same code for every
  kind. Only the fact grid differs, and it reads from the kind's detail table.
- **Facets** — `FacetService` becomes kind-aware. `byMake`, `byYear` and `byMileage`
  join `vehicle_detail`. Price bands and closing-soon stay on `lot` and work for all.

## Admin

- `FreightRateResource` already exists and is unchanged.
- `LotForm` gains `kind`, `provenance`, `title`, `description`, and a kind-aware
  section for the detail fields.
- `CurbWeightLookup` applies only to `vehicle` lots.
- New `GoodsDetail` / `VehicleDetail` Filament sections rather than a second resource,
  so a lot is still edited in one place.

## What does not change

- Any money arithmetic. Quote, wallet, ledger, deposits, balance — untouched. Reworking
  *where* the freight floor applies does not change a single line of cent arithmetic.
- The auction machine. Bids, increments, reserve, anti-snipe, finalisation — untouched.
  Every kind bids the same way.
- `ShipmentService` and its paid-before-loading gate — untouched apart from the one
  `select()` below.
- Payment, FX, WhatsApp milestones.

## What does change

- **The freight basis moves from per-lot to per-shipment.** `QuoteService` applies the
  minimum consignment charge *and* flat insurance per lot today; both become
  shipment-level costs allocated across that shipment's lots. `QuoteServiceTest` and
  `FreightEstimateEndpointTest` encode the old rule and get rewritten, not adjusted.

This is the one part of the freight work this spec reopens. The alternative — a $250 floor
and $107 flat insurance on a 2-cedis lot — produces a quote no buyer would accept and no
seller would honour.

## Known costs, stated up front

Counted, not guessed.

- **`ShipmentService.php:215`** — `->select('lot.id', 'lot.make', 'lot.model',
  'lot.year')`. Becomes a join, or uses `lot.title`. One site.
- **27 direct vehicle-attribute reads across 9 files** — `Lot`, `CurbWeightLookup`,
  and 7 Blade files. Grep for `\$lot->(make|model|year|vin|trim|odometer|damage_primary)`.
- **12 title strings across 8 Blade files**, listed above.
- **13 test files** construct lots with `make` / `model` inline: `LotPresentationTest`,
  `CorridorQueueTest`, `WhatsAppNotificationTest`, `QuoteServiceTest`,
  `CorridorDashboardTest`, `DisplayCurrencyTest`, `FacetServiceTest`,
  `CurbWeightLookupTest`, `LotLandedCostTest`, `FreightEstimateEndpointTest`,
  `ClearanceTest`, `FxServiceTest`, `ShipmentCorridorTest`. A factory or test helper
  absorbs this rather than 13 hand-edits.
- **The `curb_weight_kg` → `weight_kg` rename** touches `Lot`, `LotForm`,
  `CurbWeightLookup`, `QuoteService`, the freight migration and three test files. It
  does **not** touch `CurbWeightSeeder` — that seeds the `curb_weights` lookup table,
  not the lot column.
- **`FacetServiceTest` has three partition invariants**, not one: closing bands, price
  bands and year bands must each account for every open lot exactly once. All three
  must survive the `vehicle_detail` join.

## Testing

TDD, as everywhere else here.

- Migration test: every existing lot survives, keeps its condition, gains a title, and
  has a `vehicle_detail` row. Count in equals count out.
- **Smallest real case first.** A 2-cedis item's freight is a sane fraction of its price,
  never $250. This is the test that fails if the per-lot floor survives.
- **Shared freight divides without losing cents.** Three lots in one shipment get shares
  that sum to exactly the shipment's freight — no lost or invented cents from rounding.
- `QuoteService`: a `goods` lot is priced exactly as a vehicle lot is at the same weight.
  No branch on kind in the money path.
- `CurbWeightLookup`: a `goods` lot is never weighed from the vehicle table.
- Endpoint: a `goods` lot returns the same keys.
- Storefront: a pallet tile filters to pallets and shows only pallets.
- Facets: vehicle facets return zero for a pallet board rather than erroring or leaking.
- Existing tests stay green, apart from the two freight tests the floor change rewrites
  and the two known-red landed-cost tests below.

### Current suite: 193 tests, 191 passing, 2 red

Both failures are `LotLandedCostTest`, and both are red for a **known gap rather than a
regression**. Worth writing down because it changes what "already working" means.

The landed-cost feature exists at the wrong altitude:

- **Homepage** has a worked example. `ShopController::landedExample()` picks
  `Shipment::whereHas('lots')->latest('created_at')->first()` — the most recent
  shipment — and renders *"What one vehicle costs, landed"*. The copy hardcodes
  vehicle and vehicle-count language.
- **The lot page** `/lots/{lot}` has no landed-cost section at all.
  `ShopController::lot()` passes exactly `lot`, `bids`, `freight`.
- `ClearanceService` is complete and correct — it has `landedCost()`,
  `landedCostPerLot()` and `landedCostBreakdown()`. The service layer was finished; the
  view never was.

So the tests were written for a per-lot figure that a buyer is about to bid on, and the
implementation produced a generic marketing band instead.

### Decided: fix it in this pass

The per-lot panel is a section of the lot page, so it has to be kind-aware anyway. Doing
it here avoids building the section twice and clears two red tests.

What changes:

- `ShopController::lot()` gains a landed-cost block. It reads
  `ClearanceService::landedCostBreakdown()` for **the shipment this lot is allocated to**,
  and finds **this lot's** row in it. Not the most recent shipment — the actual one.
- The homepage's `landedExample()` stays as the marketing band it is, but its copy stops
  hardcoding vehicles and count-divided-by-cars.
- The panel's wording moves from *"divided across N vehicles"* to *"divided across N
  lots"*, because after this spec it may be dividing a pallet's cost, not a car's.
- A lot with no shipment allocated still shows no panel. Placeholder zeros are worse than
  absence.

`ClearanceService` is not rewritten. It already answers the right questions; nothing on
the service side was wrong, only the altitude it was being read at.

## Preview artefact

`resources/views/shop/preview-kinds.blade.php` with a route wrapped in
`if (app()->environment('local'))` in `routes/web.php`. Shows the four tiles, the mixed
board and three lot pages. **Delete both when this design is signed off.**

## Explicitly out of scope

- Foreclosure as its own catalogue. It is provenance on a vehicle.
- Choosing which garage items to list. That is inventory work in Ohio, not software.
- A cart or basket so a buyer can win five things in one go. Each lot is independent for
  now; whether that becomes a problem is a question for a real buyer, not a guess.
- Volumetric / dimensional freight. Columns only, not in the quote.
- Any change to the money core.

## Build order

1. **Schema** — `kind`, `provenance`, `title`, `description`, the rename, both detail
   tables, `condition_definition`. Backfill, then drop the vehicle columns.
2. **`title` rollout** — delete the orphaned `landing-1b`, collapse the 10 live sites.
3. **Per-lot landed cost** — clears the 2 red tests. Smallest step here, so it goes early
   and stops being a red mark on the suite.
4. **Freight basis** — move the floor and insurance off per-lot onto per-shipment. Rewrites
   the 2 freight tests.
5. **Storefront** — real category tiles, kind-aware facets, kind-aware lot page.
6. **Admin** — kind-aware `LotForm`.

Steps 3 and 4 are independent of each other and of 5.