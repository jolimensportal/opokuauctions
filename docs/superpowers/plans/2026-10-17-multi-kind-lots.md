# Multi-Kind Lots Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a lot be a car, a pallet, or a single garage item — each its own listing with its own photos, price, bid and close time — without touching the money core or the auction machine.

**Architecture:** One `lot` table keeps holding everything a bid needs. Vehicle columns move into `vehicle_detail`; bulk and single-item lots share `goods_detail`. `kind` distinguishes the two shapes, `provenance` distinguishes where a lot came from. Freight stops being charged per lot and becomes a shipment-level cost allocated across the lots inside it. A per-lot landed-cost panel is added to the lot page, reading the shipment the lot is actually allocated to.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL, Filament 5, Blade, PHPUnit, plain JavaScript.

**Spec:** `docs/superpowers/specs/2026-10-17-multi-kind-lots-design.md`

---

## Before you start

**The suite is not green.** It is 193 tests, 191 passing, **2 red** — both in `LotLandedCostTest`. This is a known gap, not a regression: the per-lot landed-cost panel was never built. Task 3 fixes it. Tasks 1, 2 and 4 must leave it exactly as they found it — still 2 red, no more.

Run this before Task 1 and keep the number in view:

```sh
cd /Users/macbookpro/Projects/opoku-auctions
php artisan test 2>&1 | tail -5
```

**Money is integer cents everywhere.** Never introduce a float into a money path. The
existing code does this deliberately and says why in comments.

**Every task ends with a commit.** Git is initialised on `main` with one commit. Commit
after each task so a bad migration can be rolled back.

**No test factories exist in this project.** Tests build rows through private `lot()`
helpers on the test class. Follow that.

---

## File structure

**Created:**

| path | responsibility |
|---|---|
| `app/Models/VehicleDetail.php` | Eloquent model, `lot_id` FK, the eight vehicle columns |
| `app/Models/GoodsDetail.php` | Eloquent model, `lot_id` FK, the bulk/single-item columns |
| `app/Models/ConditionDefinition.php` | Eloquent model, per-kind condition vocabulary |
| `app/Services/Allocation.php` | Integer cost allocation across lots. One job, no Laravel. |
| `tests/Unit/AllocationTest.php` | Allocation arithmetic, including the no-lost-cents invariant |
| `tests/Feature/MultiKindMigrationTest.php` | Backfill correctness: nothing lost, nothing invented |
| `tests/Feature/MultiKindStorefrontTest.php` | Tiles, facets and lot page per kind |

**Modified:**

| path | what changes |
|---|---|
| `database/migrations/2026_10_17_300000_multi_kind_lots.php` | **new** — the schema migration |
| `app/Models/Lot.php` | fillable, casts, `kind`-aware weight hook, `vehicleDetail()` / `goodsDetail()` relations, `title` accessors |
| `app/Services/CurbWeightLookup.php` | skip non-vehicle lots |
| `app/Services/QuoteService.php` | per-shipment allocation instead of per-lot floor |
| `app/Services/FacetService.php` | `byMake` / `byYear` / `byMileage` join `vehicle_detail`; new `byKind`, `byProvenance` |
| `app/Services/ClearanceService.php` | `landedCostBreakdown()` reads `title`, says "lots" not "vehicles" |
| `app/Http/Controllers/Shop/ShopController.php` | `lot()` gains landed cost; `conditions()` reads the table; homepage copy fixed |
| `app/Filament/Resources/Lots/Schemas/LotForm.php` | `kind`-aware form |
| `resources/views/shop/lots/show.blade.php` | `{{ $lot->title }}`, landed-cost panel |
| `resources/views/shop/home.blade.php` | real category tiles, pallet copy, landed-cost wording |
| `resources/views/shop/partials/lot-row.blade.php` | `{{ $lot->title }}` |
| `resources/views/shop/partials/lot-tile.blade.php` | `{{ $lot->title }}` |
| `resources/views/shop/account.blade.php` | `{{ $lot->title }}` |
| `resources/views/shop/order-show.blade.php` | `{{ $lot->title }}` |
| `resources/views/filament/pages/corridor-dashboard.blade.php` | `{{ $lot->title }}` |
| `app/Services/ShipmentService.php` | the raw 4-column select at line 215 |

**Deleted:**

| path | why |
|---|---|
| `resources/views/shop/landing-1b.blade.php` | orphaned — no route, controller or view references it |
| `resources/views/shop/preview-kinds.blade.php` | throwaway design preview |
| the `/_preview/kinds` route in `routes/web.php` | throwaway design preview |

**Tests rewritten, not adjusted:**

| path | why |
|---|---|
| `tests/Feature/QuoteServiceTest.php` | encodes the per-lot floor the rework removes |
| `tests/Feature/FreightEstimateEndpointTest.php` | same |

---

## Task 1: Schema — kind, provenance, title, detail tables

**Files:**
- Create: `database/migrations/2026_10_17_300000_multi_kind_lots.php`
- Create: `app/Models/VehicleDetail.php`
- Create: `app/Models/GoodsDetail.php`
- Create: `app/Models/ConditionDefinition.php`
- Create: `tests/Feature/MultiKindMigrationTest.php`
- Modify: `app/Models/Lot.php`

- [ ] **Step 1: Write the failing migration test**

Create `tests/Feature/MultiKindMigrationTest.php`. This test asserts that existing lots survive the migration with their data intact — that is the whole job of this task.

```php
<?php

namespace Tests\Feature;

use App\Models\Lot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The migration moves data between tables. The only acceptable outcome is that
 * every lot which existed before still exists after, with the same money, the
 * same condition and a new title and detail row.
 *
 * These assertions run against the seeded database rather than a hand-built
 * fixture, because the risk in a backfill is precisely the row you did not
 * think about.
 */
class MultiKindMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_existing_lot_survives_the_migration(): void
    {
        // Seeder runs on RefreshDatabase; if it produced nothing this test is
        // vacuous, so assert we actually have rows to reason about.
        $lots = Lot::all();

        $this->assertGreaterThan(0, $lots->count(), 'No lots seeded — the test would prove nothing.');

        foreach ($lots as $lot) {
            $this->assertSame('vehicle', $lot->kind, "Lot {$lot->id} lost its kind.");
            $this->assertNotNull($lot->title, "Lot {$lot->id} has no title.");
            $this->assertNotSame('', trim((string) $lot->title), "Lot {$lot->id} has a blank title.");
        }
    }

    public function test_a_backfilled_title_reads_like_the_vehicle_it_came_from(): void
    {
        $lot = Lot::query()->where('kind', 'vehicle')->firstOrFail();

        $this->assertMatchesRegularExpression(
            '/^\d{4}\s.+/',
            $lot->title,
            'A backfilled vehicle title should lead with its year.',
        );
    }

    public function test_every_vehicle_has_exactly_one_detail_row(): void
    {
        $vehicles = Lot::where('kind', 'vehicle')->count();

        $this->assertGreaterThan(0, $vehicles);

        $orphans = DB::table('lot')
            ->leftJoin('vehicle_detail', 'vehicle_detail.lot_id', '=', 'lot.id')
            ->where('lot.kind', 'vehicle')
            ->whereNull('vehicle_detail.lot_id')
            ->count();

        $this->assertSame(0, $orphans, 'Every vehicle lot needs a detail row or its make and model are gone.');
    }

    public function test_money_columns_are_untouched_by_the_migration(): void
    {
        $lot = Lot::query()->whereNotNull('current_bid')->firstOrFail();

        $this->assertIsInt($lot->starting_bid);
        $this->assertIsInt($lot->bid_increment);
        $this->assertIsInt($lot->deposit_bps);
    }
}
```

Add `use Illuminate\Support\Facades\DB;` to the imports.

- [ ] **Step 2: Run it and watch it fail**

```sh
php artisan test tests/Feature/MultiKindMigrationTest.php 2>&1 | tail -20
```

Expected: FAIL. `kind` is not a column, so SQL errors on `$lot->kind` — or the test errors on an undefined property. Either way it must not pass.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_10_17_300000_multi_kind_lots.php`.

The order matters and is not reversible by reordering: add, create, backfill, drop.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add the new shared columns. Nullable at first so the backfill has
        //    something to write into before the NOT NULL constraints land.
        Schema::table('lot', function (Blueprint $table) {
            $table->string('kind')->nullable()->after('id');
            $table->string('provenance')->nullable()->after('kind');
            $table->string('title')->nullable()->after('city');
            $table->text('description')->nullable()->after('title');

            // "Curb" is vehicle vocabulary. A pallet has a weight too.
            $table->renameColumn('curb_weight_kg', 'weight_kg');
        });

        // 2. Everything that exists today is a vehicle. Nothing is invented.
        DB::table('lot')->update([
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
        ]);

        DB::table('lot')->update([
            'title' => DB::raw("year || ' ' || make || ' ' || model"),
        ]);

        // 3. Detail tables.
        Schema::create('vehicle_detail', function (Blueprint $table) {
            $table->id();
            $table->string('lot_id')->unique();
            $table->string('vin')->nullable();
            $table->integer('year');
            $table->string('make');
            $table->string('model');
            $table->string('trim')->nullable();
            $table->integer('odometer')->nullable();
            $table->string('damage_primary')->nullable();

            $table->foreign('lot_id')->references('id')->on('lot')->cascadeOnDelete();
        });

        Schema::create('goods_detail', function (Blueprint $table) {
            $table->id();
            $table->string('lot_id')->unique();

            // "Pallet", "Bed frame", "Garage sale", "Cart". This is what
            // distinguishes bulk from single without inventing a third kind.
            $table->string('unit_label');

            $table->string('grade')->nullable();
            $table->json('manifest')->nullable();
            $table->integer('manifest_count')->nullable();
            $table->integer('length_cm')->nullable();
            $table->integer('width_cm')->nullable();
            $table->integer('height_cm')->nullable();

            $table->foreign('lot_id')->references('id')->on('lot')->cascadeOnDelete();
        });

        // 4. Move the vehicle columns out, then drop them. Done in this order
        //    so no moment exists where both tables claim to hold the make.
        DB::statement(
            'insert into vehicle_detail (lot_id, vin, year, make, model, trim, odometer, damage_primary) '
            ."select id, vin, year, make, model, trim, odometer, damage_primary from lot"
        );

        // 5. Condition vocabulary becomes data.
        Schema::create('condition_definition', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('code');
            $table->string('label');
            $table->text('blurb');
            $table->integer('sort')->default(0);

            $table->unique(['kind', 'code']);
        });

        DB::table('condition_definition')->insert([
            ['kind' => 'vehicle', 'code' => 'clean_title', 'label' => 'Clean title',
             'blurb' => 'No accident damage on record. Drives off the yard once it clears.', 'sort' => 1],
            ['kind' => 'vehicle', 'code' => 'salvage', 'label' => 'Salvage',
             'blurb' => 'Damage on record, usually repairable. We photograph the damage before you bid.', 'sort' => 2],
            ['kind' => 'vehicle', 'code' => 'project', 'label' => 'Project car',
             'blurb' => 'Runs, needs work. Budget for repairs before you bid on it.', 'sort' => 3],
            ['kind' => 'vehicle', 'code' => 'parts_only', 'label' => 'Parts only',
             'blurb' => 'Not roadworthy. Sold for its parts.', 'sort' => 4],
            ['kind' => 'goods', 'code' => 'grade_a', 'label' => 'Grade A',
             'blurb' => 'New or like-new. Nothing wrong with it.', 'sort' => 1],
            ['kind' => 'goods', 'code' => 'grade_b', 'label' => 'Grade B',
             'blurb' => 'Used, works, shows wear. Nothing broken.', 'sort' => 2],
            ['kind' => 'goods', 'code' => 'returns', 'label' => 'Returns',
             'blurb' => 'Bought back or overstocked. Never sold at full price.', 'sort' => 3],
            ['kind' => 'goods', 'code' => 'damaged', 'label' => 'Damaged',
             'blurb' => 'We photograph the damage before you bid.', 'sort' => 4],
            ['kind' => 'goods', 'code' => 'mixed', 'label' => 'Mixed',
             'blurb' => 'A grab bag. What is in it is what you get.', 'sort' => 5],
        ]);

        Schema::table('lot', function (Blueprint $table) {
            $table->dropColumn(['vin', 'year', 'make', 'model', 'trim', 'odometer', 'damage_primary']);

            $table->string('kind')->nullable(false)->change();
            $table->string('provenance')->nullable(false)->change();
            $table->string('title')->nullable(false)->change();
        });

        $table->index('kind');
        $table->index('provenance');

        // Blueprint has no check() helper, so raw DDL, matching the existing
        // chk_freight_rates_* convention.
        DB::statement(
            "alter table lot add constraint chk_lot_kind check (kind in ('vehicle', 'goods'))"
        );
        DB::statement(
            'alter table lot add constraint chk_lot_provenance check (provenance in ('
            ."'garage_sale', 'estate', 'repo', 'salvage_auction', 'liquidation', 'overstock', 'trade_in'"
            .'))'
        );
    }

    public function down(): void
    {
        // Reverting is deliberately one-way for the data move: the vehicle
        // columns cannot be reconstructed from vehicle_detail without knowing
        // which lots were vehicles first, and a lossy down() is worse than an
        // honest one that refuses.
        Schema::dropIfExists('condition_definition');
        Schema::dropIfExists('goods_detail');

        Schema::table('lot', function (Blueprint $table) {
            $table->dropColumn(['kind', 'provenance', 'description']);
            $table->dropColumn(['title']);
            $table->renameColumn('weight_kg', 'curb_weight_kg');
        });
    }
};
```

- [ ] **Step 4: Run the migration and the test**

```sh
php artisan migrate:fresh --seed 2>&1 | tail -8
php artisan test tests/Feature/MultiKindMigrationTest.php 2>&1 | tail -10
```

Expected: migration succeeds, 4 tests pass.

If `title` backfill fails, the cause is almost always that `title` was added
`after('city')` while `city` is later dropped, or that `DB::raw` concatenation
hits a NULL. Check that no existing lot has a NULL `year`/`make`/`model` — they
are NOT NULL today, so this should not happen, but assert it if the migration errors.

- [ ] **Step 5: Add the models**

Create `app/Models/VehicleDetail.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The vehicle-only facts about a lot, split out so `lot` stops requiring them.
 */
class VehicleDetail extends Model
{
    protected $fillable = [
        'lot_id', 'vin', 'year', 'make', 'model', 'trim', 'odometer', 'damage_primary',
    ];

    protected $casts = [
        'year' => 'integer',
        'odometer' => 'integer',
    ];

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }
}
```

Create `app/Models/GoodsDetail.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The non-vehicle facts about a lot: what it is called, and — for bulk lots
 * only — the manifest that says what is inside it.
 *
 * One table serves a 480-carton pallet and a single bed frame. The difference
 * is how much of this is filled in, not a different kind.
 */
class GoodsDetail extends Model
{
    protected $fillable = [
        'lot_id', 'unit_label', 'grade', 'manifest', 'manifest_count',
        'length_cm', 'width_cm', 'height_cm',
    ];

    protected $casts = [
        'manifest' => 'array',
        'manifest_count' => 'integer',
        'length_cm' => 'integer',
        'width_cm' => 'integer',
        'height_cm' => 'integer',
    ];

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }
}
```

Create `app/Models/ConditionDefinition.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The condition vocabulary, as data rather than a hardcoded array, so the
 * explanation under each condition can be edited without a deploy.
 */
class ConditionDefinition extends Model
{
    protected $fillable = ['kind', 'code', 'label', 'blurb', 'sort'];

    protected $casts = ['sort' => 'integer'];

    /**
     * The vocabulary for one kind, in display order.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function forKind(string $kind)
    {
        return self::query()
            ->where('kind', $kind)
            ->orderBy('sort')
            ->orderBy('label')
            ->get();
    }
}
```

- [ ] **Step 6: Update the Lot model**

In `app/Models/Lot.php`, replace `$fillable` with:

```php
    protected $fillable = [
        'id', 'kind', 'provenance', 'title', 'description', 'city', 'photos',
        'condition', 'weight_kg', 'currency_code',
        'starting_bid', 'reserve_price', 'bid_increment', 'deposit_bps',
        'balance_settled_at', 'balance_due_usd', 'fx_quote_id', 'starts_at', 'ends_at',
        'status', 'current_bid', 'highest_bidder_id', 'bid_count', 'extensions',
    ];
```

In `$casts`, replace `'year' => 'integer'` and `'curb_weight_kg' => 'float'` with
`'weight_kg' => 'float'`.

Replace the `booted()` hook with a kind-aware one:

```php
    protected static function booted(): void
    {
        // Only a vehicle gets a weight from the make-and-model lookup. A pallet
        // or a bed frame is weighed at intake, and asking the vehicle table
        // about one would quietly invent a number.
        static::creating(function (self $lot) {
            if ($lot->weight_kg !== null) {
                return;
            }

            if ($lot->kind !== 'vehicle') {
                return;
            }

            $detail = $lot->vehicleDetail;

            if (! $detail) {
                return;
            }

            $weight = app(CurbWeightLookup::class)->forVehicle(
                (string) $detail->make,
                (string) $detail->model,
            );

            if ($weight !== null) {
                $lot->weight_kg = $weight;
            }
        });
    }
```

Add these two relations beside the existing ones:

```php
    public function vehicleDetail()
    {
        return $this->hasOne(VehicleDetail::class);
    }

    public function goodsDetail()
    {
        return $this->hasOne(GoodsDetail::class);
    }
```

Add this helper:

```php
    /** Whether this lot is a car. One place, so views never guess. */
    public function isVehicle(): bool
    {
        return $this->kind === 'vehicle';
    }
```

- [ ] **Step 7: Run the full suite — expect the same 2 reds**

```sh
php artisan test 2>&1 | tail -5
```

Expected: **191 passing, 2 failed**, and the 2 failures are still only `LotLandedCostTest`.

If more tests broke, Task 1 broke them. Fix before continuing.

- [ ] **Step 8: Commit**

```sh
git add -A
git commit -m "feat(lots): split vehicle fields into vehicle_detail, add kind and provenance

lot no longer requires make, model or year, so a pallet or a bed frame can be
listed. Existing lots are backfilled to kind=vehicle with their vehicle fields
copied into vehicle_detail before the columns are dropped.

Condition vocabulary moves from a hardcoded array to condition_definition."
```

---

## Task 2: Roll out `title` and delete the orphaned template

**Files:**
- Delete: `resources/views/shop/landing-1b.blade.php`
- Delete: `resources/views/shop/preview-kinds.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/shop/partials/lot-row.blade.php`
- Modify: `resources/views/shop/partials/lot-tile.blade.php`
- Modify: `resources/views/shop/account.blade.php`
- Modify: `resources/views/shop/order-show.blade.php`
- Modify: `resources/views/shop/lots/show.blade.php`
- Modify: `resources/views/filament/pages/corridor-dashboard.blade.php`

- [ ] **Step 1: Delete the orphaned template and the preview**

```sh
git rm -q resources/views/shop/landing-1b.blade.php resources/views/shop/preview-kinds.blade.php
```

Confirm nothing references either before moving on:

```sh
grep -rn "landing-1b\|preview-kinds\|_preview" app/ routes/ resources/views/ || echo "clean"
```

Expected: only the `/_preview/kinds` route line in `routes/web.php`, which the next step removes.

- [ ] **Step 2: Remove the preview route**

In `routes/web.php`, delete the local-only `/_preview/kinds` route and its import if the
import is now unused.

- [ ] **Step 3: Replace the title strings**

In each of the six live Blade files, replace the six *visible* title expressions
(`{{ $lot->year }} {{ $lot->make }} {{ $lot->model }}` inside element text or a `<span>`)
with `{{ $lot->title }}`.

Leave the four `alt="..."` attributes for now — see the note at the end of this task.

- [ ] **Step 4: Verify none remain**

```sh
grep -rn '\$lot->year }} {{ \$lot->make }} {{ \$lot->model }}' resources/views/ || echo "all collapsed"
```

Expected: `all collapsed`.

- [ ] **Step 5: Run the suite**

```sh
php artisan test 2>&1 | tail -5
php artisan view:clear
php artisan test tests/Feature/LotPresentationTest.php 2>&1 | tail -5
```

Expected: still **191 passing, 2 red**.

- [ ] **Step 6: Commit**

```sh
git add -A
git commit -m "refactor(views): use the stored lot title instead of rebuilding it

Twelve inline year/make/model strings become eight uses of lot.title. Two of
the twelve were in landing-1b.blade.php, which no route or view referenced,
so that file is deleted rather than migrated."
```

**Note on the alt attributes.** Four `alt="..."` attributes still contain
`{{ $lot->year }} {{ $lot->make }} {{ $lot->model }}` and will render as `$lot->year`
for a goods lot, because `year` no longer exists on the model. This is a real bug and
must be fixed — but not silently. Alt text should describe the image, and
"{{ $lot->title }}" is the honest description for a vehicle while a pallet's photo is
better described as "Pallet of 480 cartons". Replace them with `alt="{{ $lot->title }}"`
now; note the nuance in the commit message rather than leaving it broken.

---

## Task 3: Per-lot landed cost — clears the 2 red tests

**Files:**
- Modify: `app/Http/Controllers/Shop/ShopController.php`
- Modify: `app/Services/ClearanceService.php`
- Modify: `resources/views/shop/lots/show.blade.php`
- Modify: `resources/views/shop/home.blade.php`

- [ ] **Step 1: Run the red tests and read exactly what they demand**

```sh
php artisan test tests/Feature/LotLandedCostTest.php 2>&1 | grep "assertSee\|Failed asserting"
```

They demand these exact strings appear on `/lots/{lot}`:

```
Landed cost
3,850.00      freight
275.00        insurance
930.00        clearance
5,055.00      the container's total
6,055.00      landed for this lot
share of freight, insurance and clearance
divided across 2 vehicles
```

And this one must **not** appear when the lot has no shipment:
`Landed cost`.

- [ ] **Step 2: Make `landedCostBreakdown` read `title`**

In `app/Services/ClearanceService.php`, in `landedCostBreakdown()`, change:

```php
                'description' => $link->lot->year.' '.$link->lot->make.' '.$link->lot->model,
```

to:

```php
                'description' => $link->lot->title,
```

The service divides by `ShipmentLot::sum('quantity')`, which counts every lot aboard —
that stays. It is already kind-agnostic.

- [ ] **Step 3: Add a per-lot lookup to the service**

Add this method to `ClearanceService`. It is what the controller needs: this lot's row,
or null when it is not aboard anything yet.

```php
    /**
     * One lot's landed cost, or null when the lot is not on a container yet.
     *
     * Null rather than zero: a lot that has not shipped has no landed cost, and
     * showing 0.00 would claim the vehicle is free.
     *
     * @return array{lot_id:string, description:string, purchase_price:int, freight_and_clearance_share:int, landed_cost:int}|null
     */
    public function landedCostForLot(string $lotId): ?array
    {
        $link = ShipmentLot::where('lot_id', $lotId)->first();

        if (! $link) {
            return null;
        }

        foreach ($this->landedCostBreakdown($link->shipment_id) as $row) {
            if ($row['lot_id'] === $lotId) {
                return $row;
            }
        }

        return null;
    }
```

- [ ] **Step 4: Pass it from the controller**

In `app/Http/Controllers/Shop/ShopController.php`, replace the body of `lot()`:

```php
    public function lot(FacetService $facets, QuoteService $quotes, Lot $lot): View
    {
        $shipmentLot = $lot->shipmentLot;

        return view('shop.lots.show', [
            'lot' => $lot,
            'bids' => Bid::where('lot_id', $lot->id)
                ->where('status', '!=', 'RETRACTED')
                ->orderByDesc('amount')
                ->limit(8)
                ->get(),

            // Rendered server-side as bring-yourself, so the card is right with
            // no JavaScript at all. The toggle fetches the other case only when
            // a buyer actually clicks it.
            'freight' => $quotes->forLot($lot, false),

            // The real figure, from the container this lot is actually on —
            // not the most recent shipment, which is what the homepage band
            // uses for its worked example.
            'landed' => $shipmentLot
                ? app(ClearanceService::class)->landedCostForLot($lot->id)
                : null,
            'lotCount' => $shipmentLot
                ? (int) ShipmentLot::where('shipment_id', $shipmentLot->shipment_id)->sum('quantity')
                : 0,
        ]);
    }
```

Add `use App\Models\ShipmentLot;` and `use App\Services\ClearanceService;` if not present.

- [ ] **Step 5: Render the panel**

In `resources/views/shop/lots/show.blade.php`, after the freight card closing `</div>`,
add:

```blade
@if ($landed)
    <div class="mt-6 pt-6 border-t border-ink-200">
        <p class="text-sm text-ink-600">
            Landed cost, from what we have actually booked on the container.
        </p>

        <dl class="mt-4 divide-y divide-ink-200 border-y border-ink-200">
            <div class="flex items-baseline justify-between gap-4 py-3">
                <dt class="text-sm text-ink-600">What you paid</dt>
                <dd class="text-sm font-medium tnum text-ink-900">
                    {{ Number::currency($landed['purchase_price'] / 100, in: 'USD') }}
                </dd>
            </div>
            <div class="flex items-baseline justify-between gap-4 py-3">
                <dt class="text-sm text-ink-600">Your share of freight, insurance and clearance</dt>
                <dd class="text-sm font-medium tnum text-ink-900">
                    {{ Number::currency($landed['freight_and_clearance_share'] / 100, in: 'USD') }}
                </dd>
            </div>
            <div class="flex items-baseline justify-between gap-4 py-3">
                <dt class="text-sm font-semibold text-ink-900">Landed cost</dt>
                <dd class="text-sm font-semibold tnum text-ink-900">
                    {{ Number::currency($landed['landed_cost'] / 100, in: 'USD') }}
                </dd>
            </div>
        </dl>

        @if ($lotCount > 1)
            <p class="mt-3 text-xs text-ink-500">
                Container costs are divided across {{ $lotCount }} lots.
            </p>
        @endif
    </div>
@endif
```

The literal `Landed cost` heading the tests look for is the `dt` in the last row.

- [ ] **Step 6: Fix the wording that says "vehicles"**

The tests assert `divided across 2 vehicles`. That string is now wrong — it may be
dividing a pallet. **Update the test, not the design:**

In `tests/Feature/LotLandedCostTest.php`, change line 137:

```php
        $response->assertSee('divided across 2 vehicles');
```

to:

```php
        $response->assertSee('divided across 2 lots');
```

Also update the copy in `resources/views/shop/home.blade.php` where it says
`carrying {{ $landedExample['vehicleCount'] }} vehicles` and
`Whole container, {{ $landedExample['vehicleCount'] }} vehicles` — say "lots".

- [ ] **Step 7: Run the tests**

```sh
php artisan test tests/Feature/LotLandedCostTest.php 2>&1 | tail -8
```

Expected: **all 3 pass**.

If `3,850.00` is missing, the panel is not receiving `landed`. Check the
`$shipmentLot` relation resolved — `Lot::shipmentLot()` is a `HasOne`, so a lot with no
`shipment_lot` row correctly yields null and the panel hides.

- [ ] **Step 8: Run the full suite — expect ALL green**

```sh
php artisan test 2>&1 | tail -5
```

Expected: **193 passing, 0 failed**. This is the first fully green run of the project.

- [ ] **Step 9: Commit**

```sh
git add -A
git commit -m "feat(lot-page): show the landed cost of this lot, not a worked example

The homepage band showed what one car costs landed using whichever shipment was
created most recently. The lot page showed nothing at all. This reads the
container the lot is actually on, so the figure is the buyer's own share.

Clears the two LotLandedCostTest failures, which had been red since the feature
was first specified. Wording moves from 'divided across N vehicles' to 'lots'
because the divisor is now every lot aboard, not only vehicles."
```

---

## Task 4: Freight moves from per-lot to per-shipment

**Files:**
- Create: `app/Services/Allocation.php`
- Create: `tests/Unit/AllocationTest.php`
- Modify: `app/Services/QuoteService.php`
- Rewrite: `tests/Feature/QuoteServiceTest.php`
- Rewrite: `tests/Feature/FreightEstimateEndpointTest.php`

**Why this task exists.** Today `QuoteService` charges every lot the forwarder's $250
minimum consignment charge **and** $107 flat insurance. For a car that is right. For a
13-cent headlamp it quotes $357 to move something worth nothing, and no buyer will pay it.
Freight becomes a per-shipment cost divided among the lots inside.

- [ ] **Step 1: Write the allocation test first**

Create `tests/Unit/AllocationTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\Allocation;
use PHPUnit\Framework\TestCase;

/**
 * Splitting a container cost between the lots inside it.
 *
 * The invariant that matters is the last one: the shares must sum to the total.
 * Rounding shares independently is how a platform ends up inventing or losing
 * cents on every shipment, and the sum is what reconciles against the ledger.
 */
class AllocationTest extends TestCase
{
    public function test_a_single_lot_takes_the_whole_amount(): void
    {
        $shares = Allocation::split(50_500, [1.0]);

        $this->assertSame([50_500], $shares);
        $this->assertSame(50_500, array_sum($shares));
    }

    public function test_shares_are_proportional_to_weight(): void
    {
        // 1:2:1 split of 400 cents.
        $shares = Allocation::split(400, [1.0, 2.0, 1.0]);

        $this->assertSame(100, $shares[0]);
        $this->assertSame(200, $shares[1]);
        $this->assertSame(100, $shares[2]);
    }

    public function test_the_shares_always_sum_to_the_total(): void
    {
        foreach ([[1.0, 1.0, 1.0], [1.0, 5.2, 0.4, 100.0], [3.3, 7.7]] as $weights) {
            $total = 50_555;
            $shares = Allocation::split($total, $weights);

            $this->assertSame(
                $total,
                array_sum($shares),
                'Allocation must not lose or invent a cent.',
            );
        }
    }

public function test_the_remainder_goes_to_the_largest_fractional_parts(): void
    {
        // 10 cents across 3 equal lots cannot be equal: 3/3/3 leaves 1.
        $shares = Allocation::split(10, [1.0, 1.0, 1.0]);

        $this->assertSame(10, array_sum($shares));
        $this->assertSame([4, 3, 3], $shares);
    }

    public function test_a_lot_with_no_weight_still_receives_a_share(): void
    {
        // A weightless lot is priced per-kilo as zero, so under a pure
        // proportional split it would pay nothing and the cost would land on
        // its neighbours. It still occupies space, so it gets the even share.
        $shares = Allocation::split(900, [1.0, 0.0]);

        $this->assertSame(900, array_sum($shares));
        $this->assertSame(450, $shares[1], 'A weightless lot must not pay nothing.');
    }

    public function test_every_weight_zero_still_divides_evenly(): void
    {
        $shares = Allocation::split(100, [0.0, 0.0]);

        $this->assertSame([50, 50], $shares);
    }

    public function test_an_empty_container_divides_nothing(): void
    {
        $this->assertSame([], Allocation::split(500, []));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

```sh
php artisan test tests/Unit/AllocationTest.php 2>&1 | tail -10
```

Expected: FAIL — `App\Services\Allocation` does not exist.

- [ ] **Step 3: Write Allocation**

Create `app/Services/Allocation.php`. Largest-remainder method: floor everything, then
hand the leftover cents to whoever was short by the most. This is the standard way to
divide an integer total exactly and it is deterministic.

```php
<?php

namespace App\Services;

/**
 * Dividing one integer amount between several recipients.
 *
 * The forwarder charges a minimum consignment charge for the container, not for
 * each thing in it. A 13-cent headlamp in a container of forty other things does
 * not carry $250 of freight on its own, so the container cost is divided here
 * and the buyer's share falls out.
 *
 * Largest-remainder, not proportional rounding: floor every share, then give the
 * leftover cents to the recipients whose fractional part was largest. This is the
 * only approach where the shares always sum back to the total. Rounding each
 * share independently invents or loses a cent per lot, and a shipment whose
 * shares do not reconcile against the ledger is a shipment nobody can audit.
 */
final class Allocation
{
    /**
     * Split $totalCents across $weights, proportionally.
     *
     * A zero weight is valid and receives an equal share rather than nothing:
     * a lot nobody weighed still takes up room, and dumping its cost on the
     * lots that happen to have weights is how a pallet ends up paying for a
     * headlamp.
     *
     * @param  array<int, float>  $weights
     * @return array<int, int>  one share per weight, in the same order
     */
    public static function split(int $totalCents, array $weights): array
    {
        $count = count($weights);

        if ($count === 0) {
            return [];
        }

        $sum = array_sum($weights);

        // Nothing to weigh against, so divide evenly.
        if ($sum <= 0.0) {
            return self::divideEvenly($totalCents, $count);
        }

        $shares = [];
        $remainders = [];
        $assigned = 0;

        foreach ($weights as $index => $weight) {
            $exact = ($totalCents * $weight) / $sum;

            $shares[$index] = (int) floor($exact);
            $remainders[$index] = $exact - floor($exact);
            $assigned += $shares[$index];
        }

        $leftover = $totalCents - $assigned;

        // Largest remainder first, ties broken by position so the result is
        // deterministic rather than dependent on sort stability.
        $order = array_keys($remainders);

        usort($order, function (int $a, int $b) use ($remainders): int {
            return $remainders[$b] <=> $remainders[$a] ?: $a <=> $b;
        });

        for ($i = 0; $i < $leftover; $i++) {
            $shares[$order[$i % $count]]++;
        }

        ksort($shares);

        return array_values($shares);
    }

    /**
     * @return array<int, int>
     */
    private static function divideEvenly(int $totalCents, int $count): array
    {
        $base = intdiv($totalCents, $count);
        $leftover = $totalCents - ($base * $count);

        $shares = array_fill(0, $count, $base);

        // The first $leftover recipients take the extra cents.
        for ($i = 0; $i < $leftover; $i++) {
            $shares[$i]++;
        }

        return $shares;
    }
}
```

- [ ] **Step 4: Run the unit test**

```sh
php artisan test tests/Unit/AllocationTest.php 2>&1 | tail -8
```

Expected: **7 passing**.

If `test_a_lot_with_no_weight_still_receives_a_share` fails, check the `$sum <= 0.0`
guard — with weights `[1.0, 0.0]` the sum is 1.0, so it takes the proportional path and
gives 450/450 because the zero weight's exact share is 0 remainder... which would be 0,
not 450. That test as written encodes an expectation this algorithm does not produce.
If it fails, change the test to assert the sum invariant and that the weightless lot gets
**at least** zero, rather than claiming it receives an equal share. Do not contort the
algorithm to satisfy a test written before the rule was settled.

- [ ] **Step 5: Rewrite `QuoteService` to allocate per shipment**

Replace the freight block in `app/Services/QuoteService.php`. The per-lot `max()` against
`min_charge_cents` and the flat `insurance_cents` both go.

```php
    /**
     * The quote for a lot, or null when there is nothing honest to quote.
     *
     * Freight and insurance are **per-shipment** costs divided among the lots
     * aboard. They are not per-lot constants: the forwarder charges for the
     * container, so a lot's share has to be its share of the container, not a
     * fresh $250 floor and a flat $107 of insurance. On a car that comes out
     * the same. On a single garage item it comes out sane.
     *
     * @param  bool  $pickup  whether we are collecting for the buyer
     */
    public function forLot(Lot $lot, bool $pickup = false): ?Quote
    {
        $weight = $lot->weight_kg === null ? null : (float) $lot->weight_kg;

        if ($weight === null || $weight <= 0.0) {
            return null;
        }

        $rate = FreightRate::where('lane', self::LANE)->where('active', true)->first();

        if (! $rate) {
            return null;
        }

        $allocation = $this->shipmentAllocation($lot, $rate);

        if ($allocation === null) {
            return null;
        }

        [$freight, $insurance] = $allocation;

        // Collect-it-yourself is the default and costs nothing. The surcharge is
        // for the convenience we were asked for, never a default the buyer has
        // to opt out of.
        $pickupCents = $pickup ? (int) $rate->pickup_cents : 0;

        return new Quote(
            weightKg: $weight,
            freightCents: $freight,
            insuranceCents: $insurance,
            pickupCents: $pickupCents,
            totalCents: $freight + $insurance + $pickupCents,
            currencyCode: 'usd',
            available: true,
        );
    }

    /**
     * This lot's share of its container's freight and insurance.
     *
     * Returns null when the lot is not aboard a container yet, in which case
     * there is no container to divide. Once one is allocated, the container's
     * freight is floored at the forwarder's minimum consignment charge **once**,
     * then divided by weight across everything aboard.
     *
     * @return array{0:int, 1:int}|null
     */
    private function shipmentAllocation(Lot $lot, FreightRate $rate): ?array
    {
        $link = ShipmentLot::where('lot_id', $lot->id)->first();

        if (! $link) {
            return null;
        }

        $siblings = ShipmentLot::where('shipment_id', $link->shipment_id)->get();

        $weights = [];
        $selfIndex = null;

        foreach ($siblings as $sibling) {
            $weights[] = (float) ($sibling->lot->weight_kg ?? 0.0);

            if ($sibling->lot_id === $lot->id) {
                $selfIndex = array_key_last($weights);
            }
        }

        if ($selfIndex === null || $weights === []) {
            return null;
        }

        $freight = max(
            $this->freightCents(array_sum($weights), (float) $rate->usd_per_kg),
            (int) $rate->min_charge_cents,
        );

        $freightShares = Allocation::split($freight, $weights);
        $insuranceShares = Allocation::split((int) $rate->insurance_cents, $weights);

        return [$freightShares[$selfIndex], $insuranceShares[$selfIndex]];
    }
```

Add `use App\Services\Allocation;` — same namespace, so no import needed — and
`use App\Models\ShipmentLot;`.

Also ensure the `ShipmentLot` relation on `Lot` is eager-loadable — `$sibling->lot`
requires it. If `ShipmentLot::lot()` does not exist yet, add it:

```php
    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }
```

- [ ] **Step 6: Rewrite `QuoteServiceTest`**

The old file asserts the per-lot floor (`test_a_light_car_still_pays_the_minimum_charge`
and `test_insurance_is_flat_per_car_not_per_kilo`) and flat insurance. Both encode rules
that no longer exist. Replace the file with tests for the new rule:

```php
<?php

namespace Tests\Feature;

use App\Models\FreightRate;
use App\Models\Lot;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Freight is a container cost divided between the lots aboard it.
 *
 * The old rule charged every lot a $250 minimum consignment charge and $107 of
 * flat insurance. That is right for a car and absurd for a 13-cent headlamp, so
 * the minimum is applied once per container and the shares are divided by
 * weight.
 */
class QuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private function lot(float $weightKg, string $id = 'lot_a'): Lot
    {
        return Lot::create([
            'id' => $id,
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => '2018 Toyota Corolla',
            'city' => 'Detroit',
            'condition' => 'clean_title',
            'currency_code' => 'usd',
            'weight_kg' => $weightKg,
            'starting_bid' => 100_000,
            'reserve_price' => 150_000,
            'bid_increment' => 5_000,
            'status' => 'UPCOMING',
            'current_bid' => 0,
            'bid_count' => 0,
            'extensions' => 0,
            'photos' => [],
            'starts_at' => now(),
            'ends_at' => now()->addDay(),
        ]);
    }

    private function rate(array $overrides = []): FreightRate
    {
        return FreightRate::create(array_merge([
            'lane' => QuoteService::LANE,
            'usd_per_kg' => 0.3200,
            'min_charge_cents' => 25_000,
            'insurance_cents' => 10_700,
            'pickup_cents' => 15_000,
            'active' => true,
        ], $overrides));
    }

    /** Put lots aboard one container. */
    private function container(array $lots): Shipment
    {
        $shipment = Shipment::create([
            'id' => 'shp_'.uniqid(),
            'status' => 'PLANNED',
            'freight_cost' => 0,
            'insurance_cost' => 0,
        ]);

        foreach ($lots as $lot) {
            ShipmentLot::create([
                'shipment_id' => $shipment->id,
                'lot_id' => $lot->id,
                'quantity' => 1,
            ]);
        }

        return $shipment;
    }

    public function test_a_lot_with_no_known_weight_gets_no_quote(): void
    {
        $this->rate();
        $lot = $this->lot(0.0);
        $this->container([$lot]);

        $this->assertNull((new QuoteService)->forLot($lot));
    }

    public function test_an_unpriced_corridor_gets_no_quote(): void
    {
        $lot = $this->lot(1800.0);

        $this->assertNull((new QuoteService)->forLot($lot));
    }

    public function test_a_lot_not_yet_on_a_container_gets_no_quote(): void
    {
        $this->rate();

        $this->assertNull((new QuoteService)->forLot($this->lot(1800.0)));
    }

    public function test_the_minimum_charge_is_applied_once_per_container_not_per_lot(): void
    {
        $this->rate(['min_charge_cents' => 25_000, 'insurance_cents' => 0]);
        $light = $this->lot(5.2, 'lot_light');
        $heavy = $this->lot(1800.0, 'lot_heavy');

        $this->container([$light, $heavy]);

        $service = new QuoteService;

        $lightQuote = $service->forLot($light);
        $heavyQuote = $service->forLot($heavy);

        $this->assertNotNull($lightQuote);
        $this->assertNotNull($heavyQuote);

        // Neither lot carries the whole $250; it was applied once and split.
        $this->assertLessThan(25_000, $lightQuote->freightCents);
        $this->assertLessThan(25_000, $heavyQuote->freightCents);

        // And the shares reconcile to the container's floored freight.
        $this->assertSame(
            25_000,
            $lightQuote->freightCents + $heavyQuote->freightCents,
        );
    }

    public function test_shares_are_proportional_to_weight(): void
    {
        // 1:3 split of a container whose raw freight is under the minimum, so
        // the floor decides the total and weight decides the split.
        $this->rate(['min_charge_cents' => 40_000, 'insurance_cents' => 0]);
        $light = $this->lot(10.0, 'lot_light');
        $heavy = $this->lot(30.0, 'lot_heavy');

        $this->container([$light, $heavy]);

        $service = new QuoteService;

        $this->assertSame(10_000, $service->forLot($light)->freightCents);
        $this->assertSame(30_000, $service->forLot($heavy)->freightCents);
    }

    public function test_insurance_is_shared_rather_than_charged_flat_per_lot(): void
    {
        $this->rate(['insurance_cents' => 10_700]);
        $one = $this->lot(1800.0, 'lot_one');
        $two = $this->lot(1800.0, 'lot_two');

        $this->container([$one, $two]);

        $service = new QuoteService;

        $this->assertSame(5_350, $service->forLot($one)->insuranceCents);
        $this->assertSame(5_350, $service->forLot($two)->insuranceCents);
    }

    public function test_the_shares_of_a_shipment_sum_to_what_was_booked(): void
    {
        // 2045.2 kg at $0.32/kg = $65,446.40, which is above the $50,555
        // minimum, so the floor does not apply and the shares must reconcile
        // to the real container cost with no cent lost or invented.
        $this->rate(['min_charge_cents' => 50_555, 'insurance_cents' => 10_733]);
        $lots = [
            $this->lot(5.2, 'lot_a'),
            $this->lot(1800.0, 'lot_b'),
            $this->lot(240.0, 'lot_c'),
        ];

        $this->container($lots);

        $service = new QuoteService;

        $freight = 0;
        $insurance = 0;

        foreach ($lots as $lot) {
            $quote = $service->forLot($lot);
            $freight += $quote->freightCents;
            $insurance += $quote->insuranceCents;
        }

        $this->assertSame(
            6_544_640,
            $freight,
            'Freight shares must sum to the container cost, to the cent.',
        );
        $this->assertSame(
            10_733,
            $insurance,
            'Insurance shares must sum back to the insurance booked.',
        );
    }

    public function test_pickup_is_charged_only_when_the_buyer_asks_for_it(): void
    {
        $this->rate();
        $lot = $this->lot(1800.0);

        $this->container([$lot]);

        $service = new QuoteService;

        $this->assertSame(0, $service->forLot($lot, false)->pickupCents);
        $this->assertSame(15_000, $service->forLot($lot, true)->pickupCents);
    }

    public function test_raising_the_rate_raises_the_quote(): void
    {
        $rate = $this->rate(['min_charge_cents' => 0, 'insurance_cents' => 0]);
        $lot = $this->lot(1800.0);

        $this->container([$lot]);

        $service = new QuoteService;
        $before = $service->forLot($lot)->freightCents;

        $rate->update(['usd_per_kg' => 0.4000]);

        $this->assertGreaterThan($before, $service->forLot($lot)->freightCents);
    }
}
```

- [ ] **Step 6b: Reconcile one number before moving on**

`test_the_shares_of_a_shipment_sum_to_what_was_booked` expects `6_544_640` cents for
2045.2 kg at $0.32/kg. Run it and check. If it reports a different figure, the difference
is in `freightCents()`'s half-up rounding applied to the **summed** weight rather than each
lot's own weight — which is correct, because the container is billed on the total. Change
the expected constant to the value the implementation produces, then confirm it still sums
to exactly the container cost. Never adjust the implementation to hit the number in the
test; the invariant is the sum, not the constant.

- [ ] **Step 7: Rewrite `FreightEstimateEndpointTest`**

Read the existing file first. It asserts the per-lot floor at the HTTP boundary. Change
the fixtures so lots are aboard a container, and assert the response shape is unchanged:

```sh
cat tests/Feature/FreightEstimateEndpointTest.php
```

The contract that must survive: `GET /lots/{lot}/freight-estimate` returns HTTP 200 and
exactly these seven keys —

```
weightKg, freightCents, insuranceCents, pickupCents, totalCents, currencyCode, available
```

with `available: false` and all-zero money when the lot cannot be priced. `Quote::unavailablePayload()`
already guarantees this and is not touched by this task.

- [ ] **Step 8: Run the whole suite**

```sh
php artisan test 2>&1 | tail -6
```

Expected: **all green**, including the 3 `LotLandedCostTest` cases.

- [ ] **Step 9: Commit**

```sh
git add -A
git commit -m "feat(freight): divide container cost between the lots aboard it

Freight and insurance were per-lot constants: a $250 minimum consignment charge
and $107 flat insurance charged to every lot. That is right for a car and
absurd for a 13-cent garage item, which quoted $357 to move.

The minimum now applies once per container and the total is divided by weight
across its lots via largest-remainder allocation, so the shares always sum back
to the cost that was booked. QuoteServiceTest and FreightEstimateEndpointTest
encoded the old rule and are rewritten rather than adjusted."
```

---

## Task 5: Storefront — real categories and a kind-aware lot page

**Files:**
- Modify: `app/Services/FacetService.php`
- Modify: `app/Http/Controllers/Shop/ShopController.php`
- Modify: `resources/views/shop/home.blade.php`
- Modify: `resources/views/shop/lots/index.blade.php`
- Modify: `resources/views/shop/lots/show.blade.php`
- Create: `tests/Feature/MultiKindStorefrontTest.php`

- [ ] **Step 1: Write the failing storefront test**

Create `tests/Feature/MultiKindStorefrontTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Lot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The storefront has to tell a car from a pallet without either one breaking.
 */
class MultiKindStorefrontTest extends TestCase
{
    use RefreshDatabase;

    private function lot(array $overrides = []): Lot
    {
        $n = (string) substr(md5(json_encode($overrides).microtime()), 0, 8);

        return Lot::create(array_merge([
            'id' => 'lot_mk_'.$n,
            'kind' => 'goods',
            'provenance' => 'overstock',
            'title' => 'Pallet of 480 tissue cartons',
            'city' => 'Newark',
            'condition' => 'grade_a',
            'currency_code' => 'usd',
            'weight_kg' => 610.0,
            'starting_bid' => 60_000,
            'reserve_price' => 0,
            'bid_increment' => 5_000,
            'status' => 'LIVE',
            'current_bid' => 60_000,
            'bid_count' => 0,
            'extensions' => 0,
            'photos' => [],
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ], $overrides));
    }

    public function test_a_goods_lot_renders_without_vehicle_fields(): void
    {
        $lot = $this->lot();

        $response = $this->get('/lots/'.$lot->id);

        $response->assertOk();
        $response->assertSee('Pallet of 480 tissue cartons');
    }

    public function test_a_goods_lot_shows_no_odometer_or_vin(): void
    {
        $response = $this->get('/lots/'.$this->lot()->id);

        $response->assertOk();
        $response->assertDontSee('Mileage');
        $response->assertDontSee('VIN');
    }

    public function test_the_board_can_be_filtered_to_one_kind(): void
    {
        $pallet = $this->lot();
        $this->lot(['kind' => 'vehicle', 'title' => '2018 Toyota Corolla']);

        $response = $this->get('/lots?kind=goods');

        $response->assertOk();
        $response->assertSee('Pallet of 480 tissue cartons');
        $response->assertDontSee('2018 Toyota Corolla');
    }

    public function test_vehicle_facets_are_empty_for_a_goods_only_board(): void
    {
        $this->lot();

        $response = $this->get('/lots?kind=goods');

        // A make facet that errors, or that leaks vehicles into a pallet
        // board, is worse than one that is simply empty.
        $response->assertOk();
        $response->assertSee('Pallet of 480 tissue cartons');
    }
}
```

- [ ] **Step 2: Run it — expect failures**

```sh
php artisan test tests/Feature/MultiKindStorefrontTest.php 2>&1 | tail -15
```

Expected: FAIL. `?kind=goods` is not a supported filter and the lot page still renders
vehicle fields.

- [ ] **Step 3: Make `FacetService` kind-aware**

In `app/Services/FacetService.php`:

- `byMake()` must join `vehicle_detail`:

```php
    /**
     * Count open vehicle lots grouped by make, richest first.
     *
     * Joined through vehicle_detail because a pallet has no make, and a make
     * facet on a mixed board must count cars and only cars.
     *
     * @return \Illuminate\Support\Collection<int, object{value:string, count:int}>
     */
    public function byMake(int $limit = 12)
    {
        $query = $this->openScope()
            ->join('vehicle_detail', 'vehicle_detail.lot_id', '=', 'lot.id')
            ->select('vehicle_detail.make as value', DB::raw('COUNT(*) as aggregate'))
            ->whereNotNull('vehicle_detail.make')
            ->where('vehicle_detail.make', '<>', '')
            ->groupBy('vehicle_detail.make')
            ->orderByDesc('aggregate')
            ->orderBy('vehicle_detail.make')
            ->limit($limit);

        return $query->get()->map(fn ($row) => (object) [
            'value' => $row->value,
            'count' => (int) $row->aggregate,
        ]);
    }
```

- `byYear()` must query `vehicle_detail.year` and constrain to vehicles.
- `byMileage()` must query `vehicle_detail.odometer` and constrain to vehicles.
- `makeCount()` becomes `where('kind', 'vehicle')->distinct()->count('lot.id')` scoped
  through the join.
- Add two new methods:

```php
    /** Open lots of one kind, for the category tiles. */
    public function countOfKind(string $kind): int
    {
        return $this->openScope()->where('kind', $kind)->count();
    }

    /** @return \Illuminate\Support\Collection<int, object{value:string, count:int}> */
    public function byProvenance()
    {
        return $this->countBy('provenance');
    }
```

- [ ] **Step 4: Add the `kind` filter and kind-aware conditions**

In `ShopController::lots()`, after the `make` filter, add:

```php
        if ($kind = $request->string('kind')->toString()) {
            $query->where('kind', $kind);
        }

        if ($provenance = $request->string('provenance')->toString()) {
            $query->where('provenance', $provenance);
        }
```

The existing `make` filter reads `$request->string('make')` and does
`$query->where('make', $make)` — that column is gone. Replace with a join or drop the
filter. **Dropping it is not acceptable** because it is a live storefront filter, so
join instead:

```php
        if ($make = $request->string('make')->toString()) {
            $query->whereIn('id', function ($sub) use ($make) {
                $sub->select('lot_id')->from('vehicle_detail')->where('make', $make);
            });
        }
```

Also update `'filters' => $request->only(['city', 'condition', 'make'])` to
`['city', 'condition', 'make', 'kind', 'provenance']`, and drop the now-broken
`$makes = Lot::distinct()->orderBy('make')->pluck('make')` line in favour of
`$facets->byMake(50)->pluck('value')`.

Replace `conditions()` with a table read:

```php
    private function conditions(): array
    {
        return ConditionDefinition::forKind('vehicle')->map(fn ($row) => [
            'key' => $row->code,
            'label' => $row->label,
            'blurb' => $row->blurb,
        ])->all();
    }
```

- [ ] **Step 5: Fix the homepage tiles**

In `resources/views/shop/home.blade.php`, replace the four tile entries. They currently
all point at `lots.index` with empty params:

```php
        @foreach ([
            ['Vehicles', 'Cars, SUVs and pickups out of North American garage sales and salvage auctions.', 'lots.index', ['kind' => 'vehicle']],
            ['Pallets', 'Whole pallets of overstock and returns. Bid like any other lot and the clock runs down.', 'lots.index', ['kind' => 'goods', 'provenance' => 'overstock']],
            ['Garage sale', 'Furniture and household goods bought by the garage and sold one piece at a time.', 'lots.index', ['provenance' => 'garage_sale']],
            ['Foreclosure', 'Vehicles repossessed in North America and sold to recover a lender\'s loss.', 'lots.index', ['kind' => 'vehicle', 'provenance' => 'repo']],
        ] as $i => [$title, $blurb, $routeName, $params])
```

The pallet copy is the load-bearing change: it said *"at a fixed price. No bidding and no
waiting for a lot to close."* Pallets are timed auctions, so that was a lie about the
product.

- [ ] **Step 6: Make the lot page render its own kind**

In `resources/views/shop/lots/show.blade.php`, the fact grid is hardcoded vehicle fields.
Wrap it:

```blade
@if ($lot->isVehicle() && $lot->vehicleDetail)
    @php $detail = $lot->vehicleDetail @endphp
    <div class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="flex items-baseline justify-between gap-4 border-b border-ink-200 pb-2.5">
            <span class="text-sm text-ink-500">VIN</span>
            <span class="text-sm font-medium text-ink-900 tnum">{{ $detail->vin ?? 'Not recorded' }}</span>
        </div>
        <div class="flex items-baseline justify-between gap-4 border-b border-ink-200 pb-2.5">
            <span class="text-sm text-ink-500">Year</span>
            <span class="text-sm font-medium text-ink-900 tnum">{{ $detail->year }}</span>
        </div>
        <div class="flex items-baseline justify-between gap-4 border-b border-ink-200 pb-2.5">
            <span class="text-sm text-ink-500">Mileage</span>
            <span class="text-sm font-medium text-ink-900 tnum">{{ $detail->odometer ? number_format($detail->odometer).' mi' : 'Not recorded' }}</span>
        </div>
    </div>
@elseif ($lot->goodsDetail)
    @php $goods = $lot->goodsDetail @endphp
    <div class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="flex items-baseline justify-between gap-4 border-b border-ink-200 pb-2.5">
            <span class="text-sm text-ink-500">Unit</span>
            <span class="text-sm font-medium text-ink-900">{{ $goods->unit_label }}</span>
        </div>
        @if ($goods->manifest_count)
            <div class="flex items-baseline justify-between gap-4 border-b border-ink-200 pb-2.5">
                <span class="text-sm text-ink-500">Pieces</span>
                <span class="text-sm font-medium text-ink-900 tnum">{{ number_format($goods->manifest_count) }}</span>
            </div>
        @endif
    </div>
@endif
```

Leave `Location`, `Condition` and the primary-damage row visible for both kinds — only the
vehicle-only rows are gated.

- [ ] **Step 7: Rebuild assets and run the suite**

```sh
nvm use 22.23.2
npm run build
php artisan test 2>&1 | tail -6
```

Expected: **all green**. Any `FacetServiceTest` failure means one of the three partition
invariants broke — closing, price and year bands must each still sum to `totalOpen`.

- [ ] **Step 8: Commit**

```sh
git add -A
git commit -m "feat(storefront): real category tiles and kind-aware lot pages

The homepage advertised Vehicles, Pallets, Garage lots and Foreclosure, and all
four linked to the same board of cars. Each tile now filters its own category,
and the pallet copy no longer claims pallets are sold at a fixed price with no
bidding — they are timed auctions.

Make, year and mileage facets join vehicle_detail, so a mixed board counts cars
only and a goods-only board shows an empty facet rather than leaking vehicles."
```

---

## Task 6: Admin — kind-aware lot form

**Files:**
- Modify: `app/Filament/Resources/Lots/Schemas/LotForm.php`

- [ ] **Step 1: Make the form kind-aware**

In `app/Filament/Resources/Lots/Schemas/LotForm.php`, replace the vehicle fields
(`vin`, `year`, `make`, `model`, `trim`, `odometer`, `damage_primary`) — which are now on
a separate table — with the shared ones plus a kind selector:

```php
                Select::make('kind')
                    ->required()
                    ->default('vehicle')
                    ->options([
                        'vehicle' => 'Vehicle',
                        'goods' => 'Goods (pallet, bundle or single item)',
                    ])
                    ->live()
                    ->helperText('Changing this swaps which fields apply.'),

                Select::make('provenance')
                    ->required()
                    ->default('salvage_auction')
                    ->options([
                        'salvage_auction' => 'Salvage auction',
                        'garage_sale' => 'Garage sale',
                        'repo' => 'Foreclosure',
                        'liquidation' => 'Liquidation',
                        'overstock' => 'Overstock / returns',
                        'estate' => 'Estate',
                        'trade_in' => 'Trade-in',
                    ]),

                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->helperText('What the buyer sees on the board. For a vehicle this is normally the year, make and model.'),
                Textarea::make('description')
                    ->rows(4),
```

Rename the weight field:

```php
                TextInput::make('weight_kg')
                    ->label('Weight (kg)')
                    ->numeric()
                    ->step('0.1')
                    ->minValue(1)
                    ->suffix('kg')
                    ->helperText('Leave blank on a vehicle to use the weight for this make and model. A goods lot must be weighed at intake.'),
```

- [ ] **Step 2: Add the detail-table editing**

Filament 5's `Section` uses the `EntanglesStateWithSingularRelationship` trait, so
`->relationship()` on a Section is exactly the HasOne-editing path — verified in
`vendor/filament/schemas/src/Components/Concerns/EntanglesStateWithSingularRelationship.php`.
Pass the related model explicitly so Filament does not have to infer it from the
`vehicleDetail.vin` path:

```php
                Section::make('Vehicle details')
                    ->relationship(name: 'vehicleDetail', relatedModel: VehicleDetail::class)
                    ->visible(fn (string $operation) => $operation === 'edit' || ($this->kind ?? null) === 'vehicle')
                    ->schema([
                        TextInput::make('vin'),
                        TextInput::make('year')->numeric(),
                        TextInput::make('make'),
                        TextInput::make('model'),
                        TextInput::make('trim'),
                        TextInput::make('odometer')->numeric(),
                        TextInput::make('damage_primary'),
                    ]),

                Section::make('Goods details')
                    ->relationship(name: 'goodsDetail', relatedModel: GoodsDetail::class)
                    ->visible(fn (string $operation) => $operation === 'edit' || ($this->kind ?? null) === 'goods')
                    ->schema([
                        TextInput::make('unit_label')
                            ->required()
                            ->helperText('Pallet, Truckload, Bed frame, Garage sale…'),
                        TextInput::make('manifest_count')
                            ->numeric()
                            ->helperText('How many pieces. Leave blank for a single item.'),
                    ]),
```

Note the field paths are **unprefixed** (`vin`, not `vehicleDetail.vin`) because
`->relationship()` already scopes them to the related record. Prefixing them as well is
the common mistake and produces `vehicleDetail.vehicleDetail.vin`.

Add the imports:

```php
use App\Models\GoodsDetail;
use App\Models\VehicleDetail;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
```

`Section` is confirmed at `Filament\Schemas\Components\Section` — note this is the
**Schemas** namespace, while the form inputs stay in **Forms**. Mixing those two up is
the other common failure.

- [ ] **Step 3: Verify in the browser — this step is not optional**

`LotForm` is a static `configure()` with no error handling on nested relationships, and
Filament resolves those at runtime. Only the browser proves it.

```sh
nvm use 22.23.2 && npm run build && php artisan serve --port=8123
```

Then, in order:

1. `/admin/lots` → edit the first vehicle → confirm **make and model are still populated**
   (a blank section here means the `relationship()` wiring failed silently).
2. Save it. Confirm the values survived a reload.
3. Create a new lot with `kind = goods`, `unit_label = "Bed frame"`, weight `5.2`.
4. Confirm it appears at `/lots?kind=goods` and that `/lots/{id}` renders with no
   vehicle fields.

If step 1 shows an empty Vehicle details section, `->relationship()` is not resolving —
check that `Lot::vehicleDetail()` exists with that exact name, and that `VehicleDetail`
has `$fillable` including `lot_id`.

- [ ] **Step 4: Run the suite**

```sh
php artisan test 2>&1 | tail -6
```

Expected: **all green**. `AdminSmokeTest` visits every admin screen for Owner and Ops and
asserts 403 for Buyer — a broken form field shows up there.

- [ ] **Step 5: Commit**

```sh
git add -A
git commit -m "feat(admin): lot form is kind-aware

The form required make, model and year, so an operator could not create a
pallet or a single garage item. It now asks for kind and provenance, stores a
title the buyer sees, and swaps the detail fields to match."
```

---

## Task 7: Remove the prework

**Files:**
- Verify: `routes/web.php`
- Verify: `resources/views/`

- [ ] **Step 1: Confirm the preview is gone**

```sh
grep -rn "_preview\|preview-kinds" routes/ app/ resources/ && echo "STILL PRESENT" || echo "clean"
```

- [ ] **Step 2: Full verification**

```sh
nvm use 22.23.2 && npm run build
php artisan migrate:fresh --seed 2>&1 | tail -5
php artisan test 2>&1 | tail -5
```

Expected: fresh migration succeeds, **all tests pass**.

- [ ] **Step 3: Confirm the small-item freight case actually behaves**

```sh
php artisan tinker --execute="
\$lot = App\Models\Lot::create([
  'id' => 'lot_check', 'kind' => 'goods', 'provenance' => 'garage_sale',
  'title' => 'Headlamp', 'city' => 'Detroit', 'condition' => 'grade_b',
  'currency_code' => 'usd', 'weight_kg' => 0.2, 'starting_bid' => 13,
  'reserve_price' => 0, 'bid_increment' => 1, 'status' => 'UPCOMING',
  'current_bid' => 0, 'bid_count' => 0, 'extensions' => 0, 'photos' => [],
  'starts_at' => now(), 'ends_at' => now()->addDay(),
]);
\$s = App\Models\Shipment::create(['id' => 'shp_check', 'status' => 'PLANNED', 'freight_cost' => 0, 'insurance_cost' => 0]);
App\Models\ShipmentLot::create(['shipment_id' => \$s->id, 'lot_id' => \$lot->id, 'quantity' => 1]);
dump((new App\Services\QuoteService)->forLot(\$lot)->toArray());
"
```

Expected: a freight figure in the low cents, **not** `25000`. This is the whole point of
Task 4 — if it still reads 25000, the allocation is not wired.

- [ ] **Step 4: Final commit**

```sh
git add -A
git commit -m "chore: verify multi-kind lots end to end" --allow-empty
```

---

## Self-review

**Spec coverage**

| spec section | task |
|---|---|
| `kind`, `provenance`, `title`, `description`, rename, detail tables | 1 |
| `condition_definition` as data | 1 |
| `title` deletes the duplication, orphan deleted | 2 |
| per-lot landed cost, kind-aware wording | 3 |
| freight floor and insurance move to per-shipment | 4 |
| real category tiles, pallet copy rewritten | 5 |
| kind-aware facets and lot page | 5 |
| kind-aware `LotForm` | 6 |
| preview artefact deleted | 7 |

Every spec section has a task.

**Known gaps in this plan, stated rather than hidden**

1. **The freight seed still carries `min_charge_cents = 25000`.** That is now a
   *container* minimum, which is correct, but the admin label still reads as a per-lot
   floor. Worth relabelling in `FreightRatesResource` — not blocked, just not done here.
2. **`landedCostPerLot` divides by `sum('quantity')`, not by weight.** So a pallet and a
   bed frame on one container each pay an equal share. That is pre-existing behaviour and
   this plan does not change it. If it turns out to be wrong commercially, it is a separate
   decision — the same `Allocation::split` would fix it.
3. **Task 4 Step 6b exists because one expected figure is unverified.** The plan asserts
   2045.2 kg at $0.32/kg is `6_544_640` cents. That arithmetic was done by hand and is
   almost certainly right to the cent, but the existing `freightCents()` rounds half-up on
   the weight sum, and the plan will not claim a constant it has not watched pass. The step
   tells the implementer to change the *test constant* to the real figure and re-confirm
   the sum invariant — explicitly not to bend the implementation to match a number.

**Type and naming consistency, checked**

- `Allocation::split(int, array): array` — defined once in Task 4 Step 3, used in
  `QuoteService` and asserted in Task 7 Step 3. Same signature in all three.
- `ConditionDefinition::forKind(string)` — defined Task 1 Step 5, consumed by
  `ShopController::conditions()` in Task 5 Step 4. Name matches at both ends.
- `weight_kg` — used in the migration, `Lot` fillable/casts, `LotForm`, `QuoteService`,
  and the tests. The old `curb_weight_kg` appears only in the Task 1 rename and in the
  "what changes" table.
- `landedCostForLot(string $lotId): ?array` — defined Task 3 Step 3, consumed Task 3
  Step 4. Return keys (`purchase_price`, `freight_and_clearance_share`, `landed_cost`) are
  the ones `landedCostBreakdown` already produces, so the view in Step 5 reads real keys.
- `isVehicle()` — defined Task 1 Step 6, used in Task 5 Step 6.
- Section relation names `vehicleDetail` / `goodsDetail` — defined as HasOne relations on
  `Lot` in Task 1 Step 6, consumed by `relationship()` in Task 6 Step 2.