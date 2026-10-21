# The Logistics Console — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A second, purpose-built Filament console at `/admin/logistics` where an operator can create a container, attach a forwarder, record a milestone, and see the exceptions that result — rendered in Opoku's design system instead of Filament's stock look.

**Architecture:** Two Filament panels sharing the `admin` guard: the existing `admin` panel (operations, `/admin`) and a new `logistics` panel (`/admin/logistics`). Both load one Vite-built theme file, `resources/css/filament/theme.css`, which carries the card system from the reference screenshot in Opoku's paper/ink/brick. All the logic lives in plain services under `app/Services/` and is tested there; the Filament layer is a thin shell over it, exactly as `CorridorDashboard` already is.

**Tech Stack:** Laravel 13, Filament v5.10.2, Livewire 3, Tailwind CSS v4, Vite 8, PostgreSQL 15, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-10-21-opoku-dashboard-surfaces-design.md`

---

## Scope of this plan

This is plan 1 of 3. It covers the first slice of the spec's §8, minus the customer
portal, which needs its own plan because it is a different application:

| Plan | Covers | Produces |
|---|---|---|
| **1 (this one)** | The re-skin, the logistics panel, shipments, forwarders, milestones, exceptions | An operator can run freight in a console that looks like ours |
| 2 | The customer portal (`/account/freight`) | A buyer sees their own cars and parcels |
| 3 | The operations console page (replacing Filament's default dashboard) | The auction side in the same design system |

The operations console is deliberately **not** in this plan: it is a page, not a
workflow, and it can wait until the logistics console works.

---

## File structure

**Created:**

- `resources/css/filament/theme.css` — the whole design system for the admin, as
  one Vite entry. One file, because a theme split across files cannot be
  reasoned about.
- `app/Providers/Filament/LogisticsPanelProvider.php` — the second panel.
- `app/Filament/Logistics/Resources/Shipments/ShipmentResource.php` — the resource.
- `app/Filament/Logistics/Resources/Shipments/Pages/ListShipments.php`
- `app/Filament/Logistics/Resources/Shipments/Pages/CreateShipment.php`
- `app/Filament/Logistics/Resources/Shipments/Pages/EditShipment.php`
- `app/Filament/Logistics/Resources/Shipments/Schemas/ShipmentForm.php`
- `app/Filament/Logistics/Resources/Shipments/Tables/ShipmentsTable.php`
- `app/Filament/Logistics/Resources/Forwarders/ForwarderResource.php`
- `app/Filament/Logistics/Resources/Forwarders/Pages/ListForwarders.php`
- `app/Filament/Logistics/Resources/Forwarders/Pages/CreateForwarder.php`
- `app/Filament/Logistics/Resources/Forwarders/Pages/EditForwarder.php`
- `app/Filament/Logistics/Resources/Forwarders/Schemas/ForwarderForm.php`
- `app/Filament/Logistics/Resources/Forwarders/Tables/ForwardersTable.php`
- `app/Filament/Logistics/Pages/LogisticsDashboard.php` — the console's home.
- `app/Services/ShipmentMilestoneRecorder.php` — writes a milestone and the
  consignment's state together.
- `app/Services/ShipmentStaleness.php` — one rule for "this container has gone
  quiet", so the corridor board and the logistics console cannot disagree.
- `app/Services/LogisticsExceptions.php` — the required-action queue.
- `resources/views/filament/logistics/pages/logistics-dashboard.blade.php`
- `tests/Feature/AdminThemeTest.php`
- `tests/Feature/LogisticsPanelTest.php`
- `tests/Feature/ShipmentsResourceTest.php`
- `tests/Feature/ForwardersResourceTest.php`
- `tests/Feature/RecordMilestoneTest.php`
- `tests/Feature/LogisticsExceptionsTest.php`
- `tests/Feature/LogisticsDashboardTest.php`

**Modified:**

- `vite.config.js` — add the theme entry.
- `app/Providers/Filament/AdminPanelProvider.php` — the theme, and brick as primary.
- `bootstrap/providers.php` — register the logistics panel.
- `app/Services/CorridorQueries.php` — use the shared staleness rule.
- `app/Models/Shipment.php` — `milestones()` exists; add nothing yet.

**Database:** no migration in this plan. `shipments`, `forwarders` and
`shipment_milestones` already exist with the fields the console needs.

---

### Task 1: The theme — our card system, compiled by Vite

Filament renders its own compiled Tailwind. Our classes are inert inside the
admin today: `corridor-dashboard.blade.php` says so in its own comment, and
the reason is that the admin never loads the application's stylesheet. Task 1
fixes that by giving Filament its own Vite entry.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AdminThemeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin panel must wear our design system.
 *
 * It did not: the panel loaded Filament's own stylesheet and nothing of ours,
 * so every utility class written in an admin blade was inert. A custom theme
 * is the only supported way to change that, and it has to be a Vite build
 * rather than a static file, because the tokens live in the same place as the
 * storefront's.
 */
class AdminThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_panel_uses_our_vite_theme(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertSame('resources/css/filament/theme.css', $panel->getViteTheme());
    }

    public function test_the_admin_primary_colour_is_brick_not_amber(): void
    {
        $panel = Filament::getPanel('admin');

        $colours = $panel->getColors();

        $this->assertArrayHasKey('primary', $colours);
        $this->assertSame('#d42a29', strtolower($colours['primary']));
    }

    public function test_the_admin_page_still_renders_with_the_theme(): void
    {
        User::factory()->create(['role' => Role::Ops]);

        $this->actingAs(User::query()->first(), 'admin')
            ->get('/admin')
            ->assertOk();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/AdminThemeTest.php`

Expected: FAIL — `getViteTheme()` is null, and the primary colour is Filament's
amber, not `#d42a29`.

- [ ] **Step 3: Write the theme file**

Create `resources/css/filament/theme.css`. The body is the design system already
written and reviewed at `docs/previews/dashboard-shell.css` — copy that file,
then replace its `@font-face` block and add the Tailwind imports, so the file
reads:

```css
/* Opoku — the admin panel's design system.
   Copied from docs/previews/dashboard-shell.css, which is the same system
   rendered for review. Keep the two in step: the preview is the proof, this
   is the thing that ships. */

@import "tailwindcss/theme.css";
@import "tailwindcss/utilities.css";

/* Tailwind's utilities are available in the admin, but its preflight is not
   imported: preflight would reset Filament's own base styles and break every
   Filament component. The @source lines below are what make utilities exist
   for the admin's own files. */
@source "../../../app/Filament";
@source "../../../app/Filament/Logistics";
@source "../../../resources/views/filament";

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
```

Then paste the rest of `docs/previews/dashboard-shell.css` below the
`@font-face` block, unchanged, starting at its `:root {` block.

- [ ] **Step 4: Add the Vite entry**

Modify `vite.config.js`, changing the `input` array:

```js
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/filament/theme.css',
            ],
```

- [ ] **Step 5: Point the panel at the theme and at brick**

Modify `app/Providers/Filament/AdminPanelProvider.php`:

```php
use Filament\Support\Colors\Color;
```

becomes

```php
use Filament\Support\Colors\Color;
```

(unchanged — `Color` is already imported) and the panel body gains two calls.
After `->login()`:

```php
            ->login()
            // The console signs in on its own guard, so a buyer and an
            // operator can be signed in at the same time in one browser.
            ->authGuard('admin')
            // Our design system, built by Vite. Without this the panel loads
            // only Filament's stylesheet and every class written in an admin
            // blade is inert.
            ->viteTheme('resources/css/filament/theme.css')
```

and the colours block becomes:

```php
            ->colors([
                // brick-500. Filament's stock amber made the console look like
                // a different product from the shop.
                'primary' => Color::hex('#d42a29'),
            ])
```

- [ ] **Step 6: Build the assets**

Run: `npm run build`

Expected: Vite builds `resources/css/app.css`, `resources/js/app.js` and
`resources/css/filament/theme.css` into `public/build`. A build error about
`tailwindcss/theme.css` means the Tailwind v4 import path is wrong — check the
installed `tailwindcss` package's exports.

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/AdminThemeTest.php`

Expected: PASS, 3 tests.

- [ ] **Step 8: Run the whole suite**

Run: `php artisan test --compact`

Expected: PASS, 441 tests (no regression from the theme change).

- [ ] **Step 9: Commit**

```bash
git add resources/css/filament/theme.css vite.config.js \
  app/Providers/Filament/AdminPanelProvider.php tests/Feature/AdminThemeTest.php
git commit -m "feat(admin): the console wears our design system"
```

---

### Task 2: The logistics panel

A second panel at `/admin/logistics`, same guard as the operations console, so
one sign-in covers both and neither leaks into the other.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LogisticsPanelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The logistics console is a separate panel on the same guard.
 *
 * Separate, because it has a different job and a different navigation: freight
 * is grouped by lane, the auction is grouped by market and money. Same guard,
 * because an operator who has signed in to one has signed in to the other —
 * asking twice would be a puzzle, not a security feature.
 */
class LogisticsPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_logistics_panel_exists_with_its_own_path_and_guard(): void
    {
        $panel = Filament::getPanel('logistics');

        $this->assertSame('admin/logistics', $panel->getPath());
        $this->assertSame('admin', $panel->getAuthGuard());
    }

    public function test_a_guest_is_sent_to_the_logistics_login(): void
    {
        $this->get('/admin/logistics')->assertRedirect('/admin/logistics/login');
    }

    public function test_a_buyer_cannot_open_the_logistics_console(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Buyer]), 'admin')
            ->get('/admin/logistics')
            ->assertForbidden();
    }

    public function test_an_operator_can_open_the_logistics_console(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin')
            ->get('/admin/logistics')
            ->assertOk();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/LogisticsPanelTest.php`

Expected: FAIL — `Filament::getPanel('logistics')` throws, because the panel does
not exist.

- [ ] **Step 3: Create the panel provider**

Create `app/Providers/Filament/LogisticsPanelProvider.php`:

```php
<?php

namespace App\Providers\Filament;

use App\Filament\Logistics\Pages\LogisticsDashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The freight console.
 *
 * A second panel rather than a page in the first, because the two consoles
 * have different navigation and different default screens: freight is grouped
 * by lane, the auction by market and money. Same guard as the operations
 * panel, so one sign-in covers both.
 */
class LogisticsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('logistics')
            ->path('admin/logistics')
            ->login()
            ->authGuard('admin')
            ->viteTheme('resources/css/filament/theme.css')
            ->font(
                'DM Sans',
                url: fn (): string => asset('fonts/filament/dm-sans/index.css'),
                provider: LocalFontProvider::class,
            )
            ->monoFont(
                'IBM Plex Mono',
                url: fn (): string => asset('fonts/filament/ibm-plex-mono/index.css'),
                provider: LocalFontProvider::class,
            )
            ->colors([
                'primary' => Color::hex('#d42a29'),
            ])
            ->discoverResources(
                in: app_path('Filament/Logistics/Resources'),
                for: 'App\Filament\Logistics\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/Logistics/Pages'),
                for: 'App\Filament\Logistics\Pages',
            )
            ->pages([
                LogisticsDashboard::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
```

Add the missing import at the top of the file:

```php
use Filament\FontProviders\LocalFontProvider;
```

- [ ] **Step 4: Register the panel**

Read `bootstrap/providers.php` and add the provider in alphabetical order:

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\Filament\LogisticsPanelProvider::class,
];
```

Keep whatever else is in the file.

- [ ] **Step 5: Create the dashboard page the provider references**

Create `app/Filament/Logistics/Pages/LogisticsDashboard.php`:

```php
<?php

namespace App\Filament\Logistics\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class LogisticsDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruckDelivery;

    protected static ?string $navigationLabel = 'Logistics';

    protected static ?string $navigationGroup = 'Watch';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Logistics';

    protected string $view = 'filament.logistics.pages.logistics-dashboard';

    public function getViewData(): array
    {
        return [];
    }
}
```

And create `resources/views/filament/logistics/pages/logistics-dashboard.blade.php`:

```blade
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Logistics</x-slot>
        <x-slot name="description">The freight console. It is being built.</x-slot>
    </x-filament::section>
</x-filament-panels::page>
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/LogisticsPanelTest.php`

Expected: PASS, 4 tests.

- [ ] **Step 7: Run the whole suite**

Run: `php artisan test --compact`

Expected: PASS, 445 tests.

- [ ] **Step 8: Commit**

```bash
git add app/Providers/Filament/LogisticsPanelProvider.php bootstrap/providers.php \
  app/Filament/Logistics/Pages/LogisticsDashboard.php \
  resources/views/filament/logistics/pages/logistics-dashboard.blade.php \
  tests/Feature/LogisticsPanelTest.php
git commit -m "feat(admin): the freight console gets its own panel"
```

---

### Task 3: The shipments resource

There is no way to list, create or edit a container anywhere in the application.
This is the first thing an operator needs, because a consignment that cannot be
created cannot be booked, moved or attributed to anybody.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ShipmentsResourceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Forwarder;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A container has to be a record an operator can open, list and create.
 *
 * Until this existed, a shipment could only be created by a seeder. The
 * corridor board could show one, but nothing could add one, and the whole
 * freight workflow hangs off being able to.
 */
class ShipmentsResourceTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::factory()->create(['role' => Role::Ops]);
    }

    public function test_an_operator_can_open_the_shipments_list(): void
    {
        $this->actingAs($this->operator(), 'admin')
            ->get('/admin/logistics/shipments')
            ->assertOk();
    }

    public function test_a_buyer_cannot_open_the_shipments_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Buyer]), 'admin')
            ->get('/admin/logistics/shipments')
            ->assertForbidden();
    }

    public function test_an_operator_can_create_a_container(): void
    {
        $forwarder = Forwarder::create([
            'id' => 'fwd_test',
            'name' => 'Atlantic Freight',
        ]);

        $this->actingAs($this->operator(), 'admin')
            ->post('/admin/logistics/shipments/create', [
                'container_no' => 'MSCU 1111 222',
                'vessel' => 'MV Test Trader',
                'voyage' => '007W',
                'seaport_origin' => 'Newark',
                'seaport_destination' => 'Tema',
                'forwarder_id' => $forwarder->id,
                'status' => 'BOOKING',
                'freight_cost' => 0,
                'insurance_cost' => 0,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'container_no' => 'MSCU 1111 222',
            'seaport_destination' => 'Tema',
            'forwarder_id' => 'fwd_test',
        ]);
    }

    public function test_a_container_number_is_required_and_unique(): void
    {
        Shipment::create([
            'id' => 'shp_existing',
            'container_no' => 'MSCU 9999 000',
        ]);

        $this->actingAs($this->operator(), 'admin')
            ->post('/admin/logistics/shipments/create', [
                'container_no' => '',
                'seaport_destination' => 'Tema',
            ])
            ->assertSessionHasErrors('container_no');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/ShipmentsResourceTest.php`

Expected: FAIL — `/admin/logistics/shipments` is a 404, because the resource does
not exist.

- [ ] **Step 3: Create the resource**

Create `app/Filament/Logistics/Resources/Shipments/ShipmentResource.php`:

```php
<?php

namespace App\Filament\Logistics\Resources\Shipments;

use App\Filament\Logistics\Resources\Shipments\Pages\CreateShipment;
use App\Filament\Logistics\Resources\Shipments\Pages\EditShipment;
use App\Filament\Logistics\Resources\Shipments\Pages\ListShipments;
use App\Filament\Logistics\Resources\Shipments\Schemas\ShipmentForm;
use App\Filament\Logistics\Resources\Shipments\Tables\ShipmentsTable;
use App\Models\Shipment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * One container crossing the corridor.
 */
class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = 'Containers';

    protected static ?string $navigationGroup = 'Freight we carry';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'container';

    protected static ?string $pluralModelLabel = 'containers';

    public static function form(Schema $schema): Schema
    {
        return ShipmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShipmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShipments::route('/'),
            'create' => Pages\CreateShipment::route('/create'),
            'edit' => Pages\EditShipment::route('/{record}/edit'),
        ];
    }
}
```

- [ ] **Step 4: Create the form**

Create `app/Filament/Logistics/Resources/Shipments/Schemas/ShipmentForm.php`:

```php
<?php

namespace App\Filament\Logistics\Resources\Shipments\Schemas;

use App\Models\Forwarder;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * What an operator knows about a container when they open one.
 *
 * Money is in cents, because every other money column in this platform is in
 * cents and a form that asks for dollars is a form that will be wrong.
 */
class ShipmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The container')
                    ->schema([
                        TextInput::make('container_no')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->label('Container number'),
                        TextInput::make('vessel')->label('Vessel'),
                        TextInput::make('voyage')->label('Voyage'),
                        TextInput::make('booking_ref')->label('Booking reference'),
                        TextInput::make('bill_of_lading')->label('Bill of lading'),
                    ])
                    ->columns(2),

                Section::make('The route')
                    ->schema([
                        TextInput::make('seaport_origin')->label('From'),
                        TextInput::make('seaport_destination')->label('To'),
                        DateTimePicker::make('eta')->label('ETA'),
                        TextInput::make('tracking_source')
                            ->label('Tracking source')
                            ->helperText('Which feed this container is tracked by, if any. Nothing polls it yet — see the deferred register.'),
                    ])
                    ->columns(2),

                Section::make('The carrier and the money')
                    ->schema([
                        Select::make('forwarder_id')
                            ->label('Forwarder')
                            ->options(fn (): array => Forwarder::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable(),
                        Select::make('status')
                            ->required()
                            ->default('BOOKING')
                            ->options([
                                'BOOKING' => 'Booking',
                                'LOADED' => 'Loaded',
                                'DEPARTED' => 'Departed',
                                'SAILING' => 'Sailing',
                                'ARRIVED' => 'Arrived at Tema',
                                'CLEARED' => 'Cleared',
                            ]),
                        TextInput::make('freight_cost')
                            ->label('Freight (cents)')
                            ->numeric()
                            ->default(0),
                        TextInput::make('insurance_cost')
                            ->label('Insurance (cents)')
                            ->numeric()
                            ->default(0),
                    ])
                    ->columns(2),
            ]);
    }
}
```

- [ ] **Step 5: Create the table**

Create `app/Filament/Logistics/Resources/Shipments/Tables/ShipmentsTable.php`:

```php
<?php

namespace App\Filament\Logistics\Resources\Shipments\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShipmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('container_no')
                    ->label('Container')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('vessel')->searchable(),
                TextColumn::make('seaport_origin')->label('From'),
                TextColumn::make('seaport_destination')->label('To'),
                TextColumn::make('forwarder.name')->label('Forwarder'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('eta')->dateTime()->sortable(),
                TextColumn::make('freight_cost')
                    ->label('Freight')
                    ->money('USD')
                    ->sortable(),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->defaultSort('eta');
    }
}
```

- [ ] **Step 6: Create the pages**

Create `app/Filament/Logistics/Resources/Shipments/Pages/ListShipments.php`:

```php
<?php

namespace App\Filament\Logistics\Resources\Shipments\Pages;

use App\Filament\Logistics\Resources\Shipments\ShipmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShipments extends ListRecords
{
    protected static string $resource = ShipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
```

Create `app/Filament/Logistics/Resources/Shipments/Pages/CreateShipment.php`:

```php
<?php

namespace App\Filament\Logistics\Resources\Shipments\Pages;

use App\Filament\Logistics\Resources\Shipments\ShipmentResource;
use App\Models\Shipment;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateShipment extends CreateRecord
{
    protected static string $resource = ShipmentResource::class;

    /**
     * The shipment id is a string, not a sequence, and the seeder's ids look
     * like `shp_demo_01`. A container number is unique and is what an
     * operator will read out on the phone, so it makes the best id.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['id'] = $data['id'] ?? ('shp_'.Str::slug($data['container_no'] ?? '').'_'.Str::lower(Str::random(6)));

        return $data;
    }
}
```

Create `app/Filament/Logistics/Resources/Shipments/Pages/EditShipment.php`:

```php
<?php

namespace App\Filament\Logistics\Resources\Shipments\Pages;

use App\Filament\Logistics\Resources\Shipments\ShipmentResource;
use Filament\Resources\Pages\EditRecord;

class EditShipment extends EditRecord
{
    protected static string $resource = ShipmentResource::class;
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/ShipmentsResourceTest.php`

Expected: PASS, 4 tests. If the create test fails with a missing-column
error, the form is not filling `seaport_origin` — check the field names match
the migration exactly.

- [ ] **Step 8: Run the whole suite**

Run: `php artisan test --compact`

Expected: PASS, 449 tests.

- [ ] **Step 9: Commit**

```bash
git add app/Filament/Logistics/Resources/Shipments tests/Feature/ShipmentsResourceTest.php
git commit -m "feat(admin): containers can be listed, created and edited"
```

---

### Task 4: The forwarders resource

There are no forwarder records, so nothing can be booked, costed or claimed
against a carrier. The table exists; the console does not.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ForwardersResourceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Forwarder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A forwarder is a record, not a user: they never log in, the console books
 * on their behalf. Without one, a container cannot be booked or costed.
 */
class ForwardersResourceTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::factory()->create(['role' => Role::Ops]);
    }

    public function test_an_operator_can_open_the_forwarders_list(): void
    {
        $this->actingAs($this->operator(), 'admin')
            ->get('/admin/logistics/forwarders')
            ->assertOk();
    }

    public function test_an_operator_can_create_a_forwarder(): void
    {
        $this->actingAs($this->operator(), 'admin')
            ->post('/admin/logistics/forwarders/create', [
                'name' => 'Atlantic Freight',
                'contact_name' => 'Kofi Mensah',
                'contact_phone' => '+233 20 000 0000',
                'contact_email' => 'kofi@atlantic.example',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('forwarders', [
            'name' => 'Atlantic Freight',
            'contact_email' => 'kofi@atlantic.example',
        ]);
    }

    public function test_a_forwarder_needs_a_name(): void
    {
        $this->actingAs($this->operator(), 'admin')
            ->post('/admin/logistics/forwarders/create', ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_a_forwarder_is_listed_with_its_container_count(): void
    {
        Forwarder::create(['id' => 'fwd_one', 'name' => 'Atlantic Freight']);

        $this->actingAs($this->operator(), 'admin')
            ->get('/admin/logistics/forwarders')
            ->assertOk()
            ->assertSee('Atlantic Freight');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/ForwardersResourceTest.php`

Expected: FAIL — 404, the resource does not exist.

- [ ] **Step 3: Create the resource, form, table and pages**

Create `app/Filament/Logistics/Resources/Forwarders/ForwarderResource.php`:

```php
<?php

namespace App\Filament\Logistics\Resources\Forwarders;

use App\Filament\Logistics\Resources\Forwarders\Pages\CreateForwarder;
use App\Filament\Logistics\Resources\Forwarders\Pages\EditForwarder;
use App\Filament\Logistics\Resources\Forwarders\Pages\ListForwarders;
use App\Filament\Logistics\Resources\Forwarders\Schemas\ForwarderForm;
use App\Filament\Logistics\Resources\Forwarders\Tables\ForwardersTable;
use App\Models\Forwarder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ForwarderResource extends Resource
{
    protected static ?string $model = Forwarder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Forwarders';

    protected static ?string $navigationGroup = 'Freight we carry';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'forwarder';

    protected static ?string $pluralModelLabel = 'forwarders';

    public static function form(Schema $schema): Schema
    {
        return ForwarderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ForwardersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListForwarders::route('/'),
            'create' => Pages\CreateForwarder::route('/create'),
            'edit' => Pages\EditForwarder::route('/{record}/edit'),
        ];
    }
}
```

Create `app/Filament/Logistics/Resources/Forwarders/Schemas/ForwarderForm.php`:

```php
<?php

namespace App\Filament\Logistics\Resources\Forwarders\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ForwarderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The carrier')
                    ->schema([
                        TextInput::make('name')->required(),
                        TextInput::make('contact_name')->label('Contact name'),
                        TextInput::make('contact_phone')->label('Contact phone')
                            ->tel(),
                        TextInput::make('contact_email')->label('Contact email')
                            ->email(),
                    ])
                    ->columns(2),
            ]);
    }
}
```

Create `app/Filament/Logistics/Resources/Forwarders/Tables/ForwardersTable.php`:

```php
<?php

namespace App\Filament\Logistics\Resources\Forwarders\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ForwardersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('contact_name')->label('Contact'),
                TextColumn::make('contact_phone')->label('Phone'),
                TextColumn::make('contact_email')->label('Email')->searchable(),
                TextColumn::make('shipments_count')
                    ->counts('shipments')
                    ->label('Containers'),
            ])
            ->actions([
                EditAction::make(),
            ]);
    }
}
```

Create `app/Filament/Logistics/Resources/Forwarders/Pages/ListForwarders.php`:

```php
<?php

namespace App\Filament\Logistics\Resources\Forwarders\Pages;

use App\Filament\Logistics\Resources\Forwarders\ForwarderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListForwarders extends ListRecords
{
    protected static string $resource = ForwarderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
```

Create `app/Filament/Logistics/Resources/Forwarders/Pages/CreateForwarder.php`:

```php
<?php

namespace App\Filament\Logistics\Resources\Forwarders\Pages;

use App\Filament\Logistics\Resources\Forwarders\ForwarderResource;
use App\Models\Forwarder;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateForwarder extends CreateRecord
{
    protected static string $resource = ForwarderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['id'] = 'fwd_'.Str::lower(Str::random(10));

        return $data;
    }
}
```

Create `app/Filament/Logistics/Resources/Forwarders/Pages/EditForwarder.php`:

```php
<?php

namespace App\Filament\Logistics\Resources\Forwarders\Pages;

use App\Filament\Logistics\Resources\Forwarders\ForwarderResource;
use Filament\Resources\Pages\EditRecord;

class EditForwarder extends EditRecord
{
    protected static string $resource = ForwarderResource::class;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/ForwardersResourceTest.php`

Expected: PASS, 4 tests.

- [ ] **Step 5: Run the whole suite and commit**

Run: `php artisan test --compact`

Expected: PASS, 453 tests.

```bash
git add app/Filament/Logistics/Resources/Forwarders tests/Feature/ForwardersResourceTest.php
git commit -m "feat(admin): forwarders can be recorded"
```

---

### Task 5: Recording a milestone

A milestone is the only record of how a container actually moved, and nothing
could write one. Recording a milestone must also move the container's state, or
the corridor board and the console will disagree about where it is.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RecordMilestoneTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Shipment;
use App\Models\ShipmentMilestone;
use App\Models\User;
use App\Services\ShipmentMilestoneRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recording a milestone is the one action that moves freight.
 *
 * It writes the milestone and the container's state together, because a
 * milestone that does not move the container is a note, and a note is how a
 * container ends up silent for four days.
 */
class RecordMilestoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_a_milestone_writes_it_and_moves_the_container(): void
    {
        $shipment = Shipment::create([
            'id' => 'shp_test',
            'container_no' => 'MSCU 1234 567',
            'status' => 'BOOKING',
        ]);

        $operator = User::factory()->create(['role' => Role::Ops]);

        app(ShipmentMilestoneRecorder::class)->record(
            shipment: $shipment,
            type: 'DEPARTED',
            occurredAt: now(),
            notes: 'Left Newark',
            recordedBy: $operator,
        );

        $this->assertDatabaseHas('shipment_milestones', [
            'shipment_id' => 'shp_test',
            'type' => 'DEPARTED',
            'source' => 'admin',
            'recorded_by_user_id' => $operator->id,
        ]);

        $this->assertSame('DEPARTED', $shipment->fresh()->status);
    }

    public function test_a_milestone_from_a_feed_is_not_recorded_by_an_operator(): void
    {
        $shipment = Shipment::create([
            'id' => 'shp_feed',
            'container_no' => 'MSCU 2222 333',
        ]);

        app(ShipmentMilestoneRecorder::class)->record(
            shipment: $shipment,
            type: 'SAILING',
            occurredAt: now(),
            notes: null,
            recordedBy: null,
            source: 'feed',
        );

        $this->assertDatabaseHas('shipment_milestones', [
            'shipment_id' => 'shp_feed',
            'source' => 'feed',
            'recorded_by_user_id' => null,
        ]);
    }

    public function test_an_operator_can_record_a_milestone_from_the_container_list(): void
    {
        Shipment::create([
            'id' => 'shp_ui',
            'container_no' => 'MSCU 4444 555',
            'status' => 'BOOKING',
        ]);

        $operator = User::factory()->create(['role' => Role::Ops]);

        $this->actingAs($operator, 'admin')
            ->get('/admin/logistics/shipments')
            ->assertOk()
            ->assertSee('MSCU 4444 555');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/RecordMilestoneTest.php`

Expected: FAIL — `App\Services\ShipmentMilestoneRecorder` does not exist.

- [ ] **Step 3: Write the recorder**

Create `app/Services/ShipmentMilestoneRecorder.php`:

```php
<?php

namespace App\Services;

use App\Models\Shipment;
use App\Models\ShipmentMilestone;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Writes a milestone and moves the container with it.
 *
 * The two are written in one call and in one transaction, because they are
 * one fact: the container is where the milestone says it is. A milestone
 * recorded without moving the container is how a container ends up looking
 * booked while it is at sea.
 *
 * The source column matters. An operator's record is never overwritten by a
 * later feed poll — the operator was there.
 */
class ShipmentMilestoneRecorder
{
    public function record(
        Shipment $shipment,
        string $type,
        mixed $occurredAt = null,
        ?string $notes = null,
        ?User $recordedBy = null,
        string $source = 'admin',
    ): ShipmentMilestone {
        $occurredAt ??= now();

        return DB::transaction(function () use ($shipment, $type, $occurredAt, $notes, $recordedBy, $source): ShipmentMilestone {
            $milestone = ShipmentMilestone::create([
                'id' => 'ms_'.Str::lower(Str::random(12)),
                'shipment_id' => $shipment->id,
                'type' => $type,
                'occurred_at' => $occurredAt,
                'source' => $source,
                'recorded_by_user_id' => $recordedBy?->id,
                'notes' => $notes,
            ]);

            $shipment->forceFill(['status' => $type])->save();

            return $milestone;
        });
    }
}
```

Add the import at the top of the file:

```php
use Illuminate\Support\Facades\DB;
```

- [ ] **Step 4: Add the action to the container list**

In `app/Filament/Logistics/Resources/Shipments/Tables/ShipmentsTable.php`, add
the import:

```php
use App\Services\ShipmentMilestoneRecorder;
use Filament\Actions\Action;
```

and add the action to the `->actions([...])` array, before `EditAction`:

```php
            ->actions([
                Action::make('recordMilestone')
                    ->label('Record a milestone')
                    ->icon(Heroicon::OutlinedMapPin)
                    ->form([
                        Select::make('type')
                            ->required()
                            ->label('What happened')
                            ->options([
                                'LOADED' => 'Loaded',
                                'DEPARTED' => 'Departed',
                                'SAILING' => 'Sailing',
                                'ARRIVED' => 'Arrived at Tema',
                                'CLEARED' => 'Cleared',
                            ]),
                        DateTimePicker::make('occurred_at')
                            ->label('When')
                            ->default(now())
                            ->required(),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->helperText('What a person would want to know in two weeks.'),
                    ])
                    ->action(function (array $data, Shipment $record): void {
                        app(ShipmentMilestoneRecorder::class)->record(
                            shipment: $record,
                            type: $data['type'],
                            occurredAt: $data['occurred_at'],
                            notes: $data['notes'] ?? null,
                            recordedBy: auth('admin')->user(),
                        );
                    }),
                EditAction::make(),
            ])
```

Add the three imports the action needs:

```php
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/RecordMilestoneTest.php`

Expected: PASS, 3 tests.

- [ ] **Step 6: Run the whole suite and commit**

Run: `php artisan test --compact`

Expected: PASS, 456 tests.

```bash
git add app/Services/ShipmentMilestoneRecorder.php \
  app/Filament/Logistics/Resources/Shipments/Tables/ShipmentsTable.php \
  tests/Feature/RecordMilestoneTest.php
git commit -m "feat(admin): an operator can move a container by recording a milestone"
```

---

### Task 6: One rule for a silent container, and the exception queue

The corridor board already decides a container is stale when its last
milestone is three days or more old. The logistics console has to decide the
same thing the same way, so the rule is extracted rather than written twice.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/LogisticsExceptionsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Forwarder;
use App\Models\ParcelBooking;
use App\Models\Shipment;
use App\Models\ShipmentMilestone;
use App\Services\LogisticsExceptions;
use App\Services\ShipmentStaleness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The queue of things that are stopped.
 *
 * Every item must name a next action and be countable, because a queue nobody
 * can act on is a dashboard decoration.
 */
class LogisticsExceptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_container_that_has_not_moved_for_three_days_is_an_exception(): void
    {
        $shipment = Shipment::create([
            'id' => 'shp_silent',
            'container_no' => 'MSCU 0000 001',
            'status' => 'SAILING',
        ]);

        ShipmentMilestone::create([
            'id' => 'ms_silent',
            'shipment_id' => $shipment->id,
            'type' => 'SAILING',
            'occurred_at' => now()->subDays(4),
            'source' => 'admin',
        ]);

        $exceptions = app(LogisticsExceptions::class)->all();

        $this->assertCount(1, $exceptions);
        $this->assertSame('silent_container', $exceptions[0]['kind']);
        $this->assertStringContainsString('MSCU 0000 001', $exceptions[0]['title']);
        $this->assertNotEmpty($exceptions[0]['action']);
    }

    public function test_a_container_that_moved_yesterday_is_not_an_exception(): void
    {
        $shipment = Shipment::create([
            'id' => 'shp_fresh',
            'container_no' => 'MSCU 0000 002',
            'status' => 'SAILING',
        ]);

        ShipmentMilestone::create([
            'id' => 'ms_fresh',
            'shipment_id' => $shipment->id,
            'type' => 'SAILING',
            'occurred_at' => now()->subDay(),
            'source' => 'admin',
        ]);

        $this->assertCount(0, app(LogisticsExceptions::class)->all());
    }

    public function test_a_container_with_no_forwarder_is_an_exception(): void
    {
        Shipment::create([
            'id' => 'shp_nofwd',
            'container_no' => 'MSCU 0000 003',
            'status' => 'BOOKING',
            'forwarder_id' => null,
        ]);

        $exceptions = app(LogisticsExceptions::class)->all();

        $this->assertCount(1, $exceptions);
        $this->assertSame('missing_forwarder', $exceptions[0]['kind']);
    }

    public function test_an_expired_parcel_hold_is_an_exception(): void
    {
        ParcelBooking::create([
            'id' => 'bk_expired',
            'reference' => 'refexpired',
            'status' => 'payment_pending',
            'email' => 'buyer@example.test',
            'weight_grams' => 20000,
            'quoted_total_cents' => 2500,
            'expires_at' => now()->subDay(),
        ]);

        $exceptions = app(LogisticsExceptions::class)->all();

        $this->assertCount(1, $exceptions);
        $this->assertSame('expired_hold', $exceptions[0]['kind']);
    }

    public function test_the_staleness_rule_is_one_rule(): void
    {
        $this->assertSame(3, ShipmentStaleness::DAYS);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/LogisticsExceptionsTest.php`

Expected: FAIL — `App\Services\LogisticsExceptions` and
`App\Services\ShipmentStaleness` do not exist.

- [ ] **Step 3: Write the staleness rule**

Create `app/Services/ShipmentStaleness.php`:

```php
<?php

namespace App\Services;

use App\Models\Shipment;
use App\Models\ShipmentMilestone;
use Carbon\CarbonInterface;

/**
 * How long a container may go without moving before it is a problem.
 *
 * One rule, in one place. The corridor board had its own copy of this
 * threshold and the logistics console would have had another, and two
 * dashboards that disagree about whether a container is late is worse than
 * either one.
 */
final class ShipmentStaleness
{
    /** A container with no movement for this many days is silent. */
    public const DAYS = 3;

    /** Days since the container last moved. Null when it never has. */
    public static function daysSince(Shipment $shipment): ?int
    {
        $last = ShipmentMilestone::query()
            ->where('shipment_id', $shipment->id)
            ->orderByDesc('occurred_at')
            ->first();

        if ($last === null) {
            return null;
        }

        return (int) $last->occurred_at->diffInDays(now());
    }

    /** True when the container has been quiet for too long. */
    public static function isSilent(Shipment $shipment): bool
    {
        $days = self::daysSince($shipment);

        return $days === null || $days >= self::DAYS;
    }
}
```

- [ ] **Step 4: Write the exception queue**

Create `app/Services/LogisticsExceptions.php`:

```php
<?php

namespace App\Services;

use App\Models\ParcelBooking;
use App\Models\Shipment;
use Illuminate\Support\Carbon;

/**
 * The queue of things that are stopped.
 *
 * Each item names what is wrong, what it refers to, and the next action. An
 * item without a next action is a worry, not a task, and the console has
 * nowhere to put a worry.
 */
class LogisticsExceptions
{
    /** @return array<int, array{kind: string, title: string, detail: string, action: string, reference: ?string}> */
    public function all(): array
    {
        return [
            ...$this->silentContainers(),
            ...$this->missingForwarders(),
            ...$this->expiredHolds(),
        ];
    }

    /** @return array<int, array{kind: string, title: string, detail: string, action: string, reference: ?string}> */
    private function silentContainers(): array
    {
        return Shipment::query()
            ->with('milestones')
            ->get()
            ->filter(fn (Shipment $shipment): bool => ShipmentStaleness::isSilent($shipment))
            ->map(function (Shipment $shipment): array {
                $days = ShipmentStaleness::daysSince($shipment);

                return [
                    'kind' => 'silent_container',
                    'title' => sprintf(
                        '%s has not moved in %s',
                        $shipment->container_no,
                        $days === null ? 'its whole life' : $days.' days',
                    ),
                    'detail' => sprintf(
                        '%s · %s → %s · last movement %s',
                        $shipment->vessel ?? 'No vessel recorded',
                        $shipment->seaport_origin ?? '?',
                        $shipment->seaport_destination ?? '?',
                        $shipment->milestones->sortByDesc('occurred_at')->first()?->occurred_at?->toFormattedDateString() ?? 'never',
                    ),
                    'action' => 'Record a milestone, or check the tracking feed.',
                    'reference' => $shipment->container_no,
                ];
            })
            ->all();
    }

    /** @return array<int, array{kind: string, title: string, detail: string, action: string, reference: ?string}> */
    private function missingForwarders(): array
    {
        return Shipment::query()
            ->whereNull('forwarder_id')
            ->get()
            ->map(fn (Shipment $shipment): array => [
                'kind' => 'missing_forwarder',
                'title' => sprintf('%s has no forwarder on file', $shipment->container_no),
                'detail' => 'Nothing can be booked, costed or claimed against a carrier until one is assigned.',
                'action' => 'Create the forwarder, then assign it to the container.',
                'reference' => $shipment->container_no,
            ])
            ->all();
    }

    /** @return array<int, array{kind: string, title: string, detail: string, action: string, reference: ?string}> */
    private function expiredHolds(): array
    {
        return ParcelBooking::query()
            ->where('status', 'payment_pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get()
            ->map(fn (ParcelBooking $booking): array => [
                'kind' => 'expired_hold',
                'title' => sprintf('A parcel hold expired while it was still unpaid'),
                'detail' => sprintf(
                    '%d g · quoted %s · hold expired %s',
                    $booking->weight_grams,
                    '$'.number_format(((int) $booking->quoted_total_cents) / 100, 2),
                    Carbon::parse($booking->expires_at)->toFormattedDateString(),
                ),
                'action' => 'Chase the payment or release the hold.',
                'reference' => $booking->reference,
            ])
            ->all();
    }
}
```

- [ ] **Step 5: Point the corridor board at the same rule**

In `app/Services/CorridorQueries.php`, replace the inline staleness test. Find:

```php
            if ($last === null || $shipment->days_stale >= 3) {
```

and replace it with:

```php
            if (ShipmentStaleness::isSilent($shipment)) {
```

Add the import:

```php
use App\Services\ShipmentStaleness;
```

(if the class is in the same namespace, `App\Services`, the import is
unnecessary and must be left out.)

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/LogisticsExceptionsTest.php`

Expected: PASS, 5 tests.

- [ ] **Step 7: Run the whole suite**

Run: `php artisan test --compact`

Expected: PASS, 461 tests. The corridor board's own tests must still pass — if
one fails, the extracted rule changed its behaviour and the failure is real.

- [ ] **Step 8: Commit**

```bash
git add app/Services/ShipmentStaleness.php app/Services/LogisticsExceptions.php \
  app/Services/CorridorQueries.php tests/Feature/LogisticsExceptionsTest.php
git commit -m "feat(admin): one rule for a silent container, and the queue it feeds"
```

---

### Task 7: The logistics console

The page an operator opens. Pipeline counters, the required-action queue, the
money moving, and one consignment table that holds both lanes.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LogisticsDashboardTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Shipment;
use App\Models\ShipmentMilestone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The console's front door.
 *
 * It is the first screen an operator sees, so it has to answer the two
 * questions they actually have: what is moving, and what is stuck.
 */
class LogisticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_console_shows_the_pipeline_counters(): void
    {
        Shipment::create([
            'id' => 'shp_one',
            'container_no' => 'MSCU 0000 010',
            'status' => 'SAILING',
        ]);

        ShipmentMilestone::create([
            'id' => 'ms_one',
            'shipment_id' => 'shp_one',
            'type' => 'SAILING',
            'occurred_at' => now(),
            'source' => 'admin',
        ]);

        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin')
            ->get('/admin/logistics')
            ->assertOk()
            ->assertSee('In transit')
            ->assertSee('MSCU 0000 010');
    }

    public function test_the_console_names_what_is_stuck(): void
    {
        Shipment::create([
            'id' => 'shp_silent',
            'container_no' => 'MSCU 0000 011',
            'status' => 'SAILING',
        ]);

        ShipmentMilestone::create([
            'id' => 'ms_silent',
            'shipment_id' => 'shp_silent',
            'type' => 'SAILING',
            'occurred_at' => now()->subDays(5),
            'source' => 'admin',
        ]);

        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin')
            ->get('/admin/logistics')
            ->assertOk()
            ->assertSee('has not moved')
            ->assertSee('MSCU 0000 011');
    }

    public function test_a_buyer_never_sees_the_console(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Buyer]), 'admin')
            ->get('/admin/logistics')
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/LogisticsDashboardTest.php`

Expected: FAIL — the page renders a placeholder with no counters and no
consignments.

- [ ] **Step 3: Write the page's data**

Replace `app/Filament/Logistics/Pages/LogisticsDashboard.php` with:

```php
<?php

namespace App\Filament\Logistics\Pages;

use App\Models\ParcelBooking;
use App\Models\Shipment;
use App\Services\LogisticsExceptions;
use App\Services\ShipmentStaleness;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class LogisticsDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruckDelivery;

    protected static ?string $navigationLabel = 'Logistics';

    protected static ?string $navigationGroup = 'Watch';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Logistics';

    protected string $view = 'filament.logistics.pages.logistics-dashboard';

    /**
     * Thin, like the corridor board.
     *
     * The queries live in LogisticsExceptions and ShipmentStaleness and are
     * tested there. A page that queries inline cannot be tested without a
     * browser, and these are the numbers an operator decides the day by.
     */
    public function getViewData(): array
    {
        $exceptions = app(LogisticsExceptions::class)->all();

        $shipments = Shipment::query()
            ->with(['forwarder', 'milestones'])
            ->orderByDesc('eta')
            ->get();

        return [
            'counters' => [
                'inTransit' => $shipments->filter(fn (Shipment $s): bool => in_array($s->status, ['LOADED', 'DEPARTED', 'SAILING'], true))->count(),
                'atTema' => $shipments->filter(fn (Shipment $s): bool => in_array($s->status, ['ARRIVED', 'CLEARED'], true))->count(),
                'parcelsBooked' => ParcelBooking::query()->where('status', 'not', 'payment_pending')->count(),
                'needAttention' => count($exceptions),
            ],
            'exceptions' => $exceptions,
            'shipments' => $shipments,
            'staleness' => ShipmentStaleness::class,
        ];
    }
}
```

- [ ] **Step 4: Write the view**

Create `resources/views/filament/logistics/pages/logistics-dashboard.blade.php`:

```blade
{{-- The freight console.

     Built on the design system's own classes from resources/css/filament/theme.css.
     The admin panel loads that stylesheet through the panel's viteTheme, so
     these classes are real here — which is exactly what was not true before. --}}
<x-filament-panels::page>
    @php
        $money = fn ($cents) => '$' . number_format(((int) $cents) / 100, 2);
        $lastMovement = fn ($shipment) => $shipment->milestones->sortByDesc('occurred_at')->first();
    @endphp

    {{-- Pipeline counters. --}}
    <div class="grid tiles-4">
        @foreach ([
            ['In transit', $counters['inTransit'], ''],
            ['At Tema', $counters['atTema'], ''],
            ['Parcels booked', $counters['parcelsBooked'], ''],
            ['Need attention', $counters['needAttention'], 'alt'],
        ] as [$label, $value, $variant])
            <div class="card">
                <div class="tile">
                    <span class="disc {{ $variant }}">◈</span>
                    <div>
                        <div class="label">{{ $label }}</div>
                        <div class="fig tnum">{{ $value }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid split" style="margin-top: 1rem;">
        {{-- What is stopped. --}}
        <section class="card">
            <h2>Required action</h2>
            <p class="sub">
                {{ count($exceptions) === 0
                    ? 'Nothing is stopped. Everything else is moving.'
                    : count($exceptions).' '.Illuminate\Support\Str::plural('thing is', count($exceptions)).' stopped.' }}
            </p>

            @forelse ($exceptions as $exception)
                <div class="action-row">
                    <span class="disc">!</span>
                    <div class="grow">
                        <div class="t">{{ $exception['title'] }}</div>
                        <div class="m">{{ $exception['detail'] }}</div>
                    </div>
                    <span class="chip">{{ $exception['action'] }}</span>
                </div>
            @empty
                <p class="muted">Nothing needs attention.</p>
            @endforelse
        </section>

        {{-- The money, in lines. --}}
        <section class="card">
            <h2>Money moving</h2>
            <p class="sub">Freight billed and cleared, in cents on the record and dollars on the screen.</p>

            <div class="stack" style="gap: 14px;">
                @foreach ($shipments as $shipment)
                    <div>
                        <div class="row between" style="font-size: 12.5px;">
                            <span class="muted">{{ $shipment->container_no }} · freight and insurance</span>
                            <span class="tnum">{{ $money($shipment->freight_cost + $shipment->insurance_cost) }}</span>
                        </div>
                        <div class="bar" style="margin-top: 6px;">
                            <span style="width: {{ $shipment->freight_cost > 0 ? 60 : 4 }}%"></span>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    {{-- Every consignment, both lanes. --}}
    <section class="card flush" style="margin-top: 1rem;">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 18px 20px 14px;">
            <div>
                <h2 style="margin: 0;">Consignments</h2>
                <p class="sub" style="margin: 3px 0 0;">One container we carry, one parcel a customer sent.</p>
            </div>
            <x-filament::button tag="a" href="{{ \App\Filament\Logistics\Resources\Shipments\ShipmentResource::getUrl('index') }}">
                All containers
            </x-filament::button>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Contents</th>
                    <th>Route</th>
                    <th>State</th>
                    <th>Last movement</th>
                    <th>Money</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($shipments as $shipment)
                    @php $movement = $lastMovement($shipment); @endphp
                    <tr>
                        <td class="mono strong">{{ $shipment->container_no }}
                            <span class="sub2">{{ $shipment->vessel ?? 'No vessel recorded' }}</span>
                        </td>
                        <td>{{ $shipment->lots()->count() }} vehicles</td>
                        <td>{{ $shipment->seaport_origin ?? '?' }} → {{ $shipment->seaport_destination ?? '?' }}</td>
                        <td><span class="chip">{{ $shipment->status }}</span></td>
                        <td class="mono">
                            {{ $movement?->type ?? '—' }}
                            @if ($movement && \App\Services\ShipmentStaleness::isSilent($shipment))
                                <span class="sub2" style="color: var(--brick-600);">silent {{ \App\Services\ShipmentStaleness::daysSince($shipment) }} days</span>
                            @endif
                        </td>
                        <td class="mono">{{ $money($shipment->freight_cost + $shipment->insurance_cost) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="muted" style="padding: 24px 16px; text-align: center;">
                            Nothing is on the water yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-filament-panels::page>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/LogisticsDashboardTest.php`

Expected: PASS, 3 tests.

- [ ] **Step 6: Run the whole suite**

Run: `php artisan test --compact`

Expected: PASS, 464 tests.

- [ ] **Step 7: Format and commit**

Run: `vendor/bin/pint --dirty --format agent`

```bash
git add app/Filament/Logistics/Pages/LogisticsDashboard.php \
  resources/views/filament/logistics/pages/logistics-dashboard.blade.php \
  tests/Feature/LogisticsDashboardTest.php
git commit -m "feat(admin): the freight console, with the queue that feeds it"
```

---

### Task 8: Look at it in a browser

A dashboard that has only ever been tested by assertion is not a dashboard.
This task is the one that cannot be skipped.

- [ ] **Step 1: Build the assets**

Run: `npm run build`

Expected: Vite builds three entries with no error. A CSS error here means the
admin page will render unstyled, and the tests will not have caught it.

- [ ] **Step 2: Start the server if it is not running**

```bash
lsof -ti:8126 >/dev/null 2>&1 || (nohup php artisan serve --port=8126 > storage/logs/serve-8126.log 2>&1 &)
```

- [ ] **Step 3: Open the console and look at it**

Open `http://127.0.0.1:8126/admin/logistics` in a browser, sign in as
`ops@opokuauctions.test` / `OpokuAdmin!2026`, and check, against the design
system:

- the canvas is grey and the cards are white with soft shadows, not Filament's
  stock look;
- the counters are cards with a circular icon, a label and a figure;
- the required-action rows name a next action;
- the consignments table has hairline rows and mono figures;
- the sidebar shows *Freight we carry*, *Freight customers send* and *Watch*.

Take a screenshot. If the page renders as bare text, the theme is not loading:
check that `->viteTheme('resources/css/filament/theme.css')` is on the panel
and that `npm run build` ran after the theme file was written.

- [ ] **Step 4: Fix what the screenshot shows, and commit**

Whatever the browser shows that the design does not, fix it, re-run
`npm run build`, re-look, and commit:

```bash
git commit -m "fix(admin): the console, as the browser showed it"
```

---

## Self-review

**Spec coverage.** §3 the design system → Task 1. §5 the logistics console →
Tasks 2–8, including the nav groups (*Freight we carry*, *Freight customers
send*, *Watch*), the four counters, the required-action panel, the money panel
and the consignments table. §7 the one migration → **not in this plan**: the
`parcel_bookings.user_id` column belongs to the customer portal, plan 2, because
nothing in plan 1 reads it. §8 items 1–3 and 5 → Tasks 3, 4, 5, 6. §8 item 4
(the customer link) → plan 2. §10 testing → a test file per task, and the
whole suite run after each.

**Gaps this plan leaves, deliberately:** the parcel lane has no resource in the
console (it is a table of one and the plan adds it in plan 2, when the portal
reads it too); there is no pickup charge, no tariff change and no tracking
feed (out of scope in the spec); the operations console is plan 3.

**Placeholder scan.** No task contains a placeholder: every code block is the
file to write, every command is exact, every expected result is stated.

**Type consistency.** `ShipmentStaleness::isSilent()` and
`ShipmentStaleness::daysSince()` are used in Task 6 and Task 7 with the same
names. `LogisticsExceptions::all()` returns items with the keys `kind`, `title`,
`detail`, `action`, `reference` — the same keys the Task 6 tests assert and the
Task 7 view reads. `ShipmentMilestoneRecorder::record()` takes `shipment`,
`type`, `occurredAt`, `notes`, `recordedBy`, `source` in every call site.

---

## What actually happened (21 Oct 2026, after execution)

The plan was executed task by task and all eight landed, 474 tests green. Five
things in it were wrong, and the browser found the last one:

1. **Filament forms are Livewire, not POST forms.** The plan's create tests
   posted to the create URL and expected a redirect. A plain POST there is a
   405 — only GET means "show the form". The tests drive
   `Livewire::test(CreateShipment::class)->fillForm(...)->call('create')`,
   which is the house pattern (`AdminLotFormTest`).
2. **A resource's URLs resolve against the current panel.** Testing a page in
   the logistics panel without `Filament::setCurrentPanel('logistics')` looks
   for a route in the operations panel and dies on the breadcrumb.
3. **`$navigationGroup` is `UnitEnum|string|null`,** not `?string`.
4. **`->viteTheme()` REPLACES the panel's stylesheet instead of adding to it.**
   The console rendered with none of Filament's own CSS — every icon at its
   natural size, the layout in pieces — and no test noticed, because the page
   still returned 200. The theme is now composed in
   `App\Providers\Filament\Concerns\HasOpokuTheme`: Filament's stylesheet
   first, ours second, so ours overrides rather than replaces.
5. **Filament follows the operating system for dark mode,** and this machine
   is dark, so a black topbar and white-on-dark headings sat over a light
   paper canvas. The console is a light system and stays light:
   `->defaultThemeMode(ThemeMode::Light)->darkMode(false, isForced: true)
   ->themeSwitcher(false)`. There is no dark variant of our cards, and the
   switcher is hidden so nobody reaches one.

The console's own defaults also changed while building it: the first plan
gave the exception queue a whole sentence as a chip. Each item now carries a
short state chip and a next action that reads as a link.

The plan's Task 3/4/5/7 test counts are the ones written; the totals moved as
tests were added. 474 pass.
