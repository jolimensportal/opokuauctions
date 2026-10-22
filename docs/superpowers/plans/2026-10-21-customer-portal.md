# The Customer Portal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A standalone account area at `/account/freight` where a buyer sees the cars we are carrying for them and the parcels they have sent us — with its own chrome, in the same design system as the console, and never inside the storefront's header and footer.

**Architecture:** A plain Blade + Livewire page, not Filament — a buyer must never touch operator software. It reads the same records the console writes: a buyer's cars are the lots whose `highest_bidder_id` is them, joined to the shipment through `shipment_lots`; their parcels are `parcel_bookings`, which gain a nullable `user_id` so a signed-in buyer can be found. The design system is shared: the component CSS moves out of the admin theme into one file both surfaces load.

**Tech Stack:** Laravel 13, Livewire 3, Tailwind CSS v4, Vite 8, PostgreSQL 15, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-10-21-opoku-dashboard-surfaces-design.md` §6, §7, §8.4, §10

---

## Scope of this plan

Plan 2 of 3. Plan 1 built the console; plan 3 replaces the operations console page.
This plan is the one migration the spec called for (`parcel_bookings.user_id`)
and the customer surface it exists for.

**Deliberately out of scope, and why:** the reference design also shows an
"ID for clearance" upload and a "collection point" address. Neither has a data
model, and building one means file storage, document privacy, retention and
validation. A button that does nothing is worse than an absent one, so the
portal's required-action panel carries only what the database can prove: a
parcel waiting for payment. The other two go to the deferred register with
their reason.

## File structure

**Created:**

- `resources/css/opoku-ui.css` — the shared design system: tokens and the
  component classes (card, tile, chip, banner, timeline, action-row, bar).
- `resources/css/portal.css` — the portal's own entry: the shared system
  plus the portal chrome (top bar, icon rail).
- `resources/views/portal/layout.blade.php` — the portal's own shell.
- `resources/views/portal/freight.blade.php` — the page.
- `app/Http/Controllers/Shop/CustomerFreightController.php`
- `app/Services/CustomerFreight.php` — what a buyer's freight is.
- `database/migrations/2026_10_21_200000_add_user_id_to_parcel_bookings_table.php`
- `tests/Feature/ParcelBookingUserLinkTest.php`
- `tests/Feature/CustomerFreightServiceTest.php`
- `tests/Feature/CustomerFreightPortalTest.php`

**Modified:**

- `resources/css/filament/theme.css` — imports the shared system instead of
  carrying it.
- `app/Models/ParcelBooking.php` — `user_id` and the relation.
- `app/Services/ParcelBookingService.php` — writes `user_id` when the buyer
  is signed in.
- `app/Http/Controllers/Shop/SenderQuoteController.php` — passes the signed-in
  buyer's id into the booking.
- `routes/web.php` — the portal route.
- `vite.config.js` — the portal's CSS entry.

---

### Task 1: One design system, two surfaces

The component classes live in the admin theme file, so the storefront's
portal cannot use them. They move into one file both load.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SharedDesignSystemTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The console and the customer portal are one product.
 *
 * The component classes lived inside the admin theme file, so the portal
 * could not use them without copying them, and a copy would drift. One
 * shared file, loaded by both.
 */
class SharedDesignSystemTest extends TestCase
{
    public function test_the_shared_design_system_exists(): void
    {
        $this->assertFileExists(resource_path('css/opoku-ui.css'));
    }

    public function test_the_shared_design_system_holds_the_components_both_surfaces_use(): void
    {
        $css = (string) file_get_contents(resource_path('css/opoku-ui.css'));

        foreach (['.card', '.tile', '.chip', '.action-row', '.banner', '.timeline', '.bar'] as $selector) {
            $this->assertStringContainsString($selector, $css, "{$selector} is missing from the shared system");
        }
    }

    public function test_both_surfaces_load_the_shared_system(): void
    {
        $admin = (string) file_get_contents(resource_path('css/filament/theme.css'));
        $portal = (string) file_get_contents(resource_path('css/portal.css'));

        $this->assertStringContainsString('opoku-ui.css', $admin);
        $this->assertStringContainsString('opoku-ui.css', $portal);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/SharedDesignSystemTest.php`

Expected: FAIL — `resources/css/opoku-ui.css` does not exist.

- [ ] **Step 3: Move the shared system into one file**

Create `resources/css/opoku-ui.css`. It takes the `:root` token block and
every component class from `resources/css/filament/theme.css` — the tokens,
`.card`, `.tile`, `.disc`, `.chip`, `.banner`, `.action-row`, `.timeline`,
`.bar`, `.grid`, `.label`, `.muted`, `.strong`, `.row`, `.grow`, `.btn`,
`.field`, `.filters`, `.pill`, `.stack` and the rest — and adds a comment
saying what it is:

```css
/* Opoku — the design system, shared by every surface.

   The console and the customer portal are the same product and must look
   like it, so the components live here once and both load this file. A copy
   would drift, and a drift is how the shop and the console stop looking
   like the same company.

   Type and colour are Opoku's: DM Sans, IBM Plex Mono for figures, paper
   and ink with brick as the only accent. The components are the reference
   screenshot's: a light grey canvas, white cards with soft shadows, circular
   icon tiles, pill chips. */
```

Then edit `resources/css/filament/theme.css` so that the shared parts are
gone and it imports the shared file instead. Its head becomes:

```css
/* Opoku — the admin panel's design system.

   The components live in resources/css/opoku-ui.css, which the customer
   portal loads too. This file adds what only the console needs: Filament's
   utilities and our fonts.

   The fonts are not declared here. The panel loads DM Sans and IBM Plex Mono
   through its own font providers, and a second @font-face for the same
   family would fight with them.

   Tailwind's utilities are available in the admin, but its preflight is not
   imported: preflight would reset Filament's own base styles and break every
   Filament component. The @source lines are what make utilities exist for the
   admin's own files. */

@import "tailwindcss/theme.css";
@import "tailwindcss/utilities.css";
@import "../opoku-ui.css";

@source "../../../app/Filament";
@source "../../../resources/views/filament";
```

- [ ] **Step 4: Add the portal's CSS entry**

Create `resources/css/portal.css`:

```css
/* Opoku — the customer portal.

   Its own application, its own chrome, our design system. The fonts are
   declared here because the portal does not load the storefront's
   stylesheet: the account area is a separate application that happens to
   share the brand. */

@import "tailwindcss/theme.css";
@import "tailwindcss/utilities.css";
@import "../opoku-ui.css";

@font-face {
    font-family: 'DM Sans';
    font-style: normal;
    font-weight: 400 700;
    font-display: swap;
    src: url('/fonts/DMSans-var.woff2') format('woff2-variations');
}

@font-face {
    font-family: 'IBM Plex Mono';
    font-style: normal;
    font-weight: 400;
    font-display: swap;
    src: url('/fonts/IBMPlexMono-400.woff2') format('woff2');
}

@font-face {
    font-family: 'IBM Plex Mono';
    font-style: normal;
    font-weight: 500;
    font-display: swap;
    src: url('/fonts/IBMPlexMono-500.woff2') format('woff2');
}

@font-face {
    font-family: 'IBM Plex Mono';
    font-style: normal;
    font-weight: 600;
    font-display: swap;
    src: url('/fonts/IBMPlexMono-600.woff2') format('woff2');
}

/* The portal's own chrome. The top bar and the icon rail, in the reference
   screenshot's arrangement. */

.portal-shell {
    display: grid;
    grid-template-columns: 68px 1fr;
    min-height: 100vh;
    background: var(--canvas);
}

.portal-rail {
    background: var(--paper);
    border-right: 1px solid var(--ink-100);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding: 20px 0;
}

.portal-rail a {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    text-decoration: none;
    color: var(--ink-500);
    font-size: 15px;
}

.portal-rail a.on { background: var(--ink-950); color: var(--paper); }
.portal-rail a.spacer { margin-top: 10px; }

.portal-topbar {
    height: 68px;
    display: flex;
    align-items: center;
    gap: 26px;
    padding: 0 26px;
    background: var(--paper);
    border-bottom: 1px solid var(--ink-100);
}

.portal-main { padding: 26px 30px 56px; }
```

- [ ] **Step 5: Add the Vite entry**

In `vite.config.js`, the `input` array becomes:

```js
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/filament/theme.css',
                'resources/css/portal.css',
            ],
```

- [ ] **Step 6: Build and run the test**

Run: `npm run build` then
`php artisan test --compact tests/Feature/SharedDesignSystemTest.php`

Expected: PASS, 3 tests. A build error about the import path means the
`@import "../opoku-ui.css"` is resolving from the wrong directory.

- [ ] **Step 7: Run the whole suite and commit**

Run: `php artisan test --compact`

Expected: PASS, 477 tests.

```bash
git add resources/css resources/../vite.config.js tests/Feature/SharedDesignSystemTest.php
git commit -m "refactor(css): one design system, loaded by the console and the portal"
```

---

### Task 2: The one migration — a parcel belongs to a buyer

`parcel_bookings` has an email and no user. A signed-in buyer's parcel cannot
be found for them, which is why the portal's shipping column would be empty
for exactly the people who signed in.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ParcelBookingUserLinkTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ParcelBooking;
use App\Models\User;
use App\Services\ParcelBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A parcel a signed-in buyer sent is theirs.
 *
 * The booking table had an email and no user, so a buyer who signed in and
 * sent a parcel had no way of finding it. Email is not a link: it is a
 * string somebody typed, and it can be wrong.
 */
class ParcelBookingUserLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bookings_table_can_hold_a_user(): void
    {
        $this->assertTrue(Schema::hasColumn('parcel_bookings', 'user_id'));
    }

    public function test_a_signed_in_buyer_owns_the_booking_they_make(): void
    {
        $buyer = User::factory()->create(['role' => Role::Buyer]);

        $booking = app(ParcelBookingService::class)->open(
            quoteInput: [
                'country' => 'us',
                'postcode' => '07030',
                'unit' => 'parcel',
                'weight_kg' => 20,
                'length_cm' => 60,
                'width_cm' => 40,
                'height_cm' => 40,
                'pickup' => false,
            ],
            quotedTotalCents: 2500,
            rateCardRowId: 'row-newark-air-standard',
            email: $buyer->email,
            userId: $buyer->id,
        );

        $this->assertSame($buyer->id, $booking->user_id);
        $this->assertTrue($booking->user->is($buyer));
    }

    public function test_a_guest_booking_has_no_user(): void
    {
        $booking = app(ParcelBookingService::class)->open(
            quoteInput: [
                'country' => 'us',
                'postcode' => '07030',
                'unit' => 'parcel',
                'weight_kg' => 20,
            ],
            quotedTotalCents: 2500,
            rateCardRowId: 'row-newark-air-standard',
            email: 'guest@example.test',
        );

        $this->assertNull($booking->user_id);
        $this->assertNull($booking->user);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/ParcelBookingUserLinkTest.php`

Expected: FAIL — `parcel_bookings` has no `user_id` column.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_10_21_200000_add_user_id_to_parcel_bookings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_bookings', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->nullable()
                ->after('email')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('parcel_bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
```

- [ ] **Step 4: Give the model the column and the relation**

In `app/Models/ParcelBooking.php`, add `'user_id'` to `$fillable`, and add
the relation next to `payments()`:

```php
    /**
     * The buyer who sent this parcel, when they were signed in when they sent
     * it. Null for a guest, whose booking is only reachable by its email.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
```

and the import:

```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;
```

- [ ] **Step 5: Write the user into the booking**

In `app/Services/ParcelBookingService.php`, give `open()` a `?int $userId = null`
parameter after `$whatsappTo`, and add `'user_id' => $userId,` to the row it
creates.

- [ ] **Step 6: Pass the signed-in buyer in**

In `app/Http/Controllers/Shop/SenderQuoteController.php`, the `$bookings->open(...)`
call gains:

```php
            userId: auth('web')->id(),
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/ParcelBookingUserLinkTest.php`

Expected: PASS, 3 tests.

- [ ] **Step 8: Run the whole suite and commit**

Run: `php artisan test --compact`

Expected: PASS, 480 tests.

```bash
git add database/migrations app/Models/ParcelBooking.php app/Services/ParcelBookingService.php \
  app/Http/Controllers/Shop/SenderQuoteController.php tests/Feature/ParcelBookingUserLinkTest.php
git commit -m "feat(account): a signed-in buyer owns the parcels they send"
```

---

### Task 3: What a buyer's freight is

One service, tested without a browser, so the portal is a view over facts
rather than a place where facts are worked out.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CustomerFreightServiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ClearanceTask;
use App\Models\Lot;
use App\Models\ParcelBooking;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use App\Models\ShipmentMilestone;
use App\Models\User;
use App\Services\CustomerFreight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A buyer's freight is what they are waiting for.
 *
 * Their cars are the lots they won, joined to the container carrying them.
 * Their parcels are the bookings they sent. Nobody else's.
 */
class CustomerFreightServiceTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(): User
    {
        return User::factory()->create(['role' => Role::Buyer, 'email' => 'kwame@example.test']);
    }

    private function containerFor(User $winner): Shipment
    {
        $shipment = Shipment::create([
            'id' => 'shp_cust',
            'container_no' => 'MSCU 4471 908',
            'vessel' => 'MV Atlantic Trader',
            'seaport_origin' => 'Newark',
            'seaport_destination' => 'Tema',
            'status' => 'SAILING',
        ]);

        $lot = Lot::create([
            'id' => 'lot_cust',
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => '2021 Ford F-150',
            'city' => 'Newark',
            'photos' => [],
            'currency_code' => 'usd',
            'starting_bid' => 100000,
            'reserve_price' => 0,
            'bid_increment' => 1000,
            'starts_at' => now(),
            'ends_at' => now()->addDay(),
            'current_bid' => 385000,
            'highest_bidder_id' => $winner->id,
            'bid_count' => 3,
            'extensions' => 0,
            'deposit_bps' => 2000,
        ]);

        ShipmentLot::create(['shipment_id' => $shipment->id, 'lot_id' => $lot->id, 'quantity' => 1]);

        ShipmentMilestone::create([
            'id' => 'ms_cust',
            'shipment_id' => $shipment->id,
            'type' => 'SAILING',
            'occurred_at' => now()->subDay(),
            'source' => 'admin',
        ]);

        ClearanceTask::create([
            'id' => 'clt_cust',
            'shipment_id' => $shipment->id,
            'type' => 'DUTY_PAID',
            'position' => 3,
            'status' => 'complete',
            'cost' => 94000,
        ]);

        return $shipment;
    }

    public function test_it_finds_the_cars_a_buyer_won(): void
    {
        $buyer = $this->buyer();
        $this->containerFor($buyer);

        $freight = app(CustomerFreight::class)->for($buyer);

        $this->assertCount(1, $freight['cars']);
        $this->assertSame('2021 Ford F-150', $freight['cars'][0]['title']);
        $this->assertSame('MSCU 4471 908', $freight['cars'][0]['container_no']);
    }

    public function test_it_never_shows_another_buyers_car(): void
    {
        $buyer = $this->buyer();
        $other = User::factory()->create(['role' => Role::Buyer]);
        $this->containerFor($other);

        $freight = app(CustomerFreight::class)->for($buyer);

        $this->assertCount(0, $freight['cars']);
    }

    public function test_it_counts_the_cars_arriving(): void
    {
        $buyer = $this->buyer();
        $this->containerFor($buyer);

        $freight = app(CustomerFreight::class)->for($buyer);

        $this->assertSame(1, $freight['counters']['carsArriving']);
    }

    public function test_it_finds_the_parcels_a_buyer_sent(): void
    {
        $buyer = $this->buyer();

        ParcelBooking::create([
            'id' => 'bk_cust',
            'reference' => 'refcust',
            'status' => 'payment_pending',
            'email' => $buyer->email,
            'user_id' => $buyer->id,
            'country' => 'us',
            'postcode' => '07030',
            'unit' => 'parcel',
            'weight_grams' => 20000,
            'pickup' => false,
            'quoted_total_cents' => 2500,
            'rate_card_row_id' => 'row-newark-air-standard',
            'quote_input' => ['unit' => 'parcel'],
            'expires_at' => now()->addDay(),
        ]);

        $freight = app(CustomerFreight::class)->for($buyer);

        $this->assertCount(1, $freight['parcels']);
        $this->assertCount(1, $freight['actions']);
        $this->assertStringContainsString('$25.00', $freight['actions'][0]['title']);
    }

    public function test_it_sums_the_freight_billed_on_their_car(): void
    {
        $buyer = $this->buyer();
        $this->containerFor($buyer);

        $freight = app(CustomerFreight::class)->for($buyer);

        $this->assertSame(94000, $freight['freightBilledCents']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/CustomerFreightServiceTest.php`

Expected: FAIL — `App\Services\CustomerFreight` does not exist.

- [ ] **Step 3: Write the service**

Create `app/Services/CustomerFreight.php`:

```php
<?php

namespace App\Services;

use App\Models\ClearanceTask;
use App\Models\Lot;
use App\Models\ParcelBooking;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use App\Models\ShipmentMilestone;
use App\Models\User;

/**
 * What a buyer is waiting for.
 *
 * Their cars are the lots they won, joined to the container carrying them.
 * Their parcels are the bookings they sent, found by the user link when they
 * were signed in and by email when they were not — a guest's booking has to
 * be findable too, or a buyer who sent a parcel before signing in would see
 * an empty page and think it was lost.
 */
class CustomerFreight
{
    /**
     * @return array{
     *     cars: array<int, array<string, mixed>>,
     *     parcels: array<int, ParcelBooking>,
     *     actions: array<int, array{title: string, detail: string, action: string, tag: string, tone: string}>,
     *     counters: array{carsArriving: int, parcelsSent: int, balanceCents: int},
     *     freightBilledCents: int,
     * }
     */
    public function for(User $buyer): array
    {
        $cars = $this->cars($buyer);
        $parcels = $this->parcels($buyer);

        return [
            'cars' => $cars,
            'parcels' => $parcels,
            'actions' => $this->actions($parcels),
            'counters' => [
                'carsArriving' => count($cars),
                'parcelsSent' => count($parcels),
                'balanceCents' => 0,
            ],
            'freightBilledCents' => $this->freightBilledCents($cars),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cars(User $buyer): array
    {
        $lots = Lot::query()
            ->where('highest_bidder_id', $buyer->id)
            ->get();

        if ($lots->isEmpty()) {
            return [];
        }

        $shipments = Shipment::query()
            ->with(['milestones'])
            ->whereIn('id', ShipmentLot::query()
                ->whereIn('lot_id', $lots->pluck('id'))
                ->pluck('shipment_id'))
            ->get()
            ->keyBy('id');

        $cleared = ClearanceTask::query()
            ->whereIn('shipment_id', $shipments->keys())
            ->where('status', 'complete')
            ->pluck('shipment_id')
            ->unique();

        return $lots->map(function (Lot $lot) use ($shipments, $cleared): array {
            $shipmentLot = ShipmentLot::query()->where('lot_id', $lot->id)->first();
            $shipment = $shipmentLot ? $shipments->get($shipmentLot->shipment_id) : null;

            return [
                'title' => $lot->title,
                'amountCents' => (int) $lot->current_bid,
                'container_no' => $shipment?->container_no,
                'vessel' => $shipment?->vessel,
                'origin' => $shipment?->seaport_origin,
                'destination' => $shipment?->seaport_destination,
                'eta' => $shipment?->eta,
                'status' => $shipment?->status,
                'cleared' => $shipment ? $cleared->contains($shipment->id) : false,
                'milestones' => $shipment
                    ? $shipment->milestones->sortByDesc('occurred_at')->values()
                    : collect(),
            ];
        })->all();
    }

    /**
     * @return array<int, ParcelBooking>
     */
    private function parcels(User $buyer): array
    {
        return ParcelBooking::query()
            ->where(function ($query) use ($buyer): void {
                $query->where('user_id', $buyer->id);
                if (filled($buyer->email)) {
                    $query->orWhere('email', $buyer->email);
                }
            })
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * @param  array<int, ParcelBooking>  $parcels
     * @return array<int, array{title: string, detail: string, action: string, tag: string, tone: string}>
     */
    private function actions(array $parcels): array
    {
        $actions = [];

        foreach ($parcels as $parcel) {
            if ($parcel->status === 'booked') {
                continue;
            }

            $expired = $parcel->expires_at !== null && $parcel->expires_at->isPast();
            $money = '$'.number_format(((int) $parcel->quoted_total_cents) / 100, 2);

            $actions[] = [
                'title' => $expired
                    ? 'Your parcel hold expired, so the price has moved'
                    : sprintf('Pay %s for the parcel you sent', $money),
                'detail' => sprintf(
                    '%d g from %s · %s',
                    $parcel->weight_grams,
                    $parcel->postcode,
                    $expired ? 'get a new price and we will book it again' : 'held until '.($parcel->expires_at?->toFormattedDateString() ?? 'payment'),
                ),
                'action' => $expired ? 'Get a new price' : 'Pay and book',
                'tag' => $expired ? 'expired' : 'unpaid',
                'tone' => $expired ? 'warn' : 'bad',
            ];
        }

        return $actions;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cars
     */
    private function freightBilledCents(array $cars): int
    {
        $shipmentIds = collect($cars)
            ->pluck('container_no')
            ->filter()
            ->map(fn (string $containerNo) => Shipment::query()
                ->where('container_no', $containerNo)
                ->value('id'))
            ->filter();

        if ($shipmentIds->isEmpty()) {
            return 0;
        }

        return (int) ClearanceTask::query()
            ->whereIn('shipment_id', $shipmentIds)
            ->where('status', 'complete')
            ->sum('cost');
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/CustomerFreightServiceTest.php`

Expected: PASS, 5 tests.

- [ ] **Step 5: Run the whole suite and commit**

Run: `php artisan test --compact`

Expected: PASS, 485 tests.

```bash
git add app/Services/CustomerFreight.php tests/Feature/CustomerFreightServiceTest.php
git commit -m "feat(account): what a buyer is waiting for, in one place"
```

---

### Task 4: The portal itself

A separate application: its own top bar, its own icon rail, and no storefront
header or footer.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CustomerFreightPortalTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lot;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer portal.
 *
 * A buyer signs in and sees their own freight. It is a separate application
 * with its own chrome: the storefront's header and footer are not part of it,
 * and neither is Filament — a buyer must never touch operator software.
 */
class CustomerFreightPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/account/freight')->assertRedirect(route('login'));
    }

    public function test_a_buyer_sees_their_own_freight(): void
    {
        $buyer = User::factory()->create(['role' => Role::Buyer, 'email' => 'kwame@example.test']);

        $shipment = Shipment::create([
            'id' => 'shp_portal',
            'container_no' => 'MSCU 4471 908',
            'vessel' => 'MV Atlantic Trader',
            'seaport_origin' => 'Newark',
            'seaport_destination' => 'Tema',
            'status' => 'SAILING',
        ]);

        $lot = Lot::create([
            'id' => 'lot_portal',
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => '2021 Ford F-150',
            'city' => 'Newark',
            'photos' => [],
            'currency_code' => 'usd',
            'starting_bid' => 100000,
            'reserve_price' => 0,
            'bid_increment' => 1000,
            'starts_at' => now(),
            'ends_at' => now()->addDay(),
            'current_bid' => 385000,
            'highest_bidder_id' => $buyer->id,
            'bid_count' => 3,
            'extensions' => 0,
            'deposit_bps' => 2000,
        ]);

        ShipmentLot::create(['shipment_id' => $shipment->id, 'lot_id' => $lot->id, 'quantity' => 1]);

        $this->actingAs($buyer, 'web')
            ->get('/account/freight')
            ->assertOk()
            ->assertSee('2021 Ford F-150')
            ->assertSee('MSCU 4471 908');
    }

    public function test_the_portal_does_not_wear_the_storefront_chrome(): void
    {
        $buyer = User::factory()->create(['role' => Role::Buyer]);

        $html = $this->actingAs($buyer, 'web')->get('/account/freight')->assertOk()->getContent();

        // The storefront's band and footer. If these appear, the portal is
        // wearing the shop's clothes, which is the mistake this page exists
        // to avoid.
        $this->assertStringNotContainsString('band-ink', $html);
        $this->assertStringNotContainsString('Affordable quality auto-auctions', $html);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/CustomerFreightPortalTest.php`

Expected: FAIL — 404, the route does not exist.

- [ ] **Step 3: Add the route**

In `routes/web.php`, inside the `auth:web` group:

```php
    // The customer portal: a separate application with its own chrome.
    Route::get('/account/freight', CustomerFreightController::class)->name('freight');
```

and the import:

```php
use App\Http\Controllers\Shop\CustomerFreightController;
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/Shop/CustomerFreightController.php`:

```php
<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Services\CustomerFreight;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The customer's own freight.
 *
 * A separate application from the shop: its own top bar, its own rail, and
 * no storefront header or footer. A buyer who signs in should land in
 * something that looks like the same company, not like a page of the shop.
 */
class CustomerFreightController extends Controller
{
    public function __invoke(Request $request, CustomerFreight $freight): View
    {
        return view('portal.freight', $freight->for($request->user()));
    }
}
```

- [ ] **Step 5: Write the layout**

Create `resources/views/portal/layout.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Your freight') — Opoku Auctions</title>
    <link rel="preconnect" href="/fonts">
    @vite('resources/css/portal.css')
</head>
<body>
<div class="portal-shell">
    <nav class="portal-rail" aria-label="Account">
        <a href="{{ route('freight') }}" class="on" title="Your freight">◱</a>
        <a href="{{ route('lots.index') }}" title="Find cars">◈</a>
        <a href="{{ route('send.create') }}" title="Send a parcel">▦</a>
        <a href="{{ route('orders.index') }}" title="Your parcels">◎</a>
        <a href="{{ route('wallet') }}" title="Your wallet">$</a>
        <a href="{{ route('dashboard') }}" title="My account">▤</a>
        <a href="#" class="spacer" title="Sign out">⏻</a>
    </nav>

    <div>
        <header class="portal-topbar">
            <span class="brand"><span class="mark">Opoku</span><span class="accent">Auctions</span></span>
            <nav class="topnav">
                <a href="{{ route('lots.index') }}">Find cars</a>
                <a href="{{ route('how-it-works') }}">How to buy</a>
                <a href="{{ route('fees') }}">Fees</a>
                <a href="{{ route('freight') }}" class="on">My freight</a>
            </nav>
            <div class="topbar-right">
                <span class="icon-btn" title="USD">$</span>
                <span class="who">{{ auth()->user()?->name }}</span>
                <span class="avatar" style="background: var(--brick-500);">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(auth()->user()?->name ?? 'U', 0, 1)) }}</span>
            </div>
        </header>

        <main class="portal-main">
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
```

- [ ] **Step 6: Write the page**

Create `resources/views/portal/freight.blade.php`:

```blade
@extends('portal.layout')

@section('title', 'Your freight')

@section('content')
    @php
        $money = fn ($cents) => '$' . number_format(((int) $cents) / 100, 2);
    @endphp

    {{-- The welcome banner, in the reference screenshot's arrangement. --}}
    <section class="banner">
        <h2>Opoku welcomes you, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}.</h2>
        <p>
            From your account you can see the cars we are carrying for you, the parcels you have
            sent us, and anything we still need from you before it can move.
        </p>
    </section>

    <div class="grid tiles-3" style="margin-top: 16px;">
        <div class="card">
            <div class="tile">
                <span class="disc">◈</span>
                <div>
                    <div class="label">Cars arriving</div>
                    <div class="fig tnum">{{ $counters['carsArriving'] }}</div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="tile">
                <span class="disc">▦</span>
                <div>
                    <div class="label">Parcels you sent</div>
                    <div class="fig tnum">{{ $counters['parcelsSent'] }}</div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="tile">
                <span class="disc">$</span>
                <div>
                    <div class="label">Your balance</div>
                    <div class="fig tnum">{{ $money($counters['balanceCents']) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid split" style="margin-top: 16px;">
        <section class="card">
            <h2>Your freight</h2>
            <p class="sub">Everything moving to Tema that has your name on it.</p>

            @forelse ($cars as $car)
                <div style="padding: 12px 0 10px; border-bottom: 1px solid var(--ink-100);">
                    <div class="row between">
                        <div>
                            <div class="strong" style="font-size: 14.5px;">{{ $car['title'] }}</div>
                            <div class="muted" style="font-size: 12.5px;">
                                {{ $car['container_no'] ?? 'No container yet' }}
                                @if ($car['vessel']) · {{ $car['vessel'] }} @endif
                                @if ($car['eta']) · arriving {{ $car['eta']->toFormattedDateString() }} @endif
                            </div>
                        </div>
                        <span class="chip {{ $car['cleared'] ? 'ok' : 'warn' }}">
                            {{ $car['cleared'] ? 'cleared' : strtolower((string) $car['status']) }}
                        </span>
                    </div>
                </div>

                <ul class="timeline" style="margin-top: 14px;">
                    @foreach ($car['milestones'] as $milestone)
                        <li>
                            <span class="rail"><span class="node {{ $milestone->type === 'SAILING' ? 'now' : '' }}"></span></span>
                            <div>
                                <div class="what">{{ \Illuminate\Support\Str::headline($milestone->type) }}</div>
                                <div class="meta">{{ $milestone->notes ?? 'Recorded movement' }}</div>
                            </div>
                            <span class="when">{{ $milestone->occurred_at->toFormattedDateString() }}</span>
                        </li>
                    @endforeach
                </ul>
            @empty
                <p class="muted">Nothing is on the water for you yet.</p>
            @endforelse

            @if ($freightBilledCents > 0)
                <div style="margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--ink-100);">
                    <div class="tile">
                        <span class="disc">$</span>
                        <div>
                            <div class="label">Freight billed to Tema</div>
                            <div class="fig tnum">{{ $money($freightBilledCents) }}</div>
                            <div class="delta">duty, VAT and port charges — all receipted</div>
                        </div>
                    </div>
                </div>
            @endif
        </section>

        <section class="card">
            <h2>Required action</h2>
            <p class="sub">What we are waiting on you for.</p>

            @forelse ($actions as $action)
                <div class="action-row">
                    <span class="disc">!</span>
                    <div class="grow">
                        <div class="t">{{ $action['title'] }}</div>
                        <div class="m">{{ $action['detail'] }}</div>
                        <div class="action-link">{{ $action['action'] }}</div>
                    </div>
                    <span class="chip {{ $action['tone'] }}">{{ $action['tag'] }}</span>
                </div>
            @empty
                <p class="muted">Nothing is waiting on you.</p>
            @endforelse
        </section>
    </div>
@endsection
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/CustomerFreightPortalTest.php`

Expected: PASS, 3 tests.

- [ ] **Step 8: Run the whole suite, format and commit**

Run: `php artisan test --compact` then `vendor/bin/pint --dirty --format agent`

Expected: PASS, 488 tests.

```bash
git add app/Http/Controllers/Shop/CustomerFreightController.php resources/views/portal \
  routes/web.php tests/Feature/CustomerFreightPortalTest.php
git commit -m "feat(account): the customer portal, as its own application"
```

---

### Task 5: Look at it in a browser

- [ ] **Step 1: Build the assets**

Run: `npm run build`

- [ ] **Step 2: Open it and look**

Open `http://127.0.0.1:8126/account/freight` as a signed-in buyer and check
against the reference screenshot:

- the icon rail on the left and the top bar, with no storefront header and no
  ink footer band;
- the welcome banner;
- three tiles with circular icons and mono figures;
- the freight panel with the timeline;
- the required-action panel.

Take a screenshot. If the page is unstyled, the portal's CSS entry did not
build — check `npm run build` output for `portal-*.css`.

- [ ] **Step 3: Fix what the screenshot shows, and commit**

```bash
git commit -m "fix(account): the portal, as the browser showed it"
```
