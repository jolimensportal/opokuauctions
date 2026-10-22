# The Operations Console — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Filament's stock dashboard at `/admin` with the operations console: lots live, open bids, money owed to us, funds held, an activity timeline, and a required-action queue whose items are derived from the data rather than written by hand.

**Architecture:** One service, `App\Services\OperationsOverview`, tested without a browser, returning the counters, the activity and the actions. A custom Filament page renders them in the shared design system, replacing Filament's own `Dashboard` class on the operations panel.

**Tech Stack:** Laravel 13, Filament v5.10.2, Livewire 3, Tailwind CSS v4, Vite 8, PostgreSQL 15, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-10-21-opoku-dashboard-surfaces-design.md` §4, §10

---

## The required-action queue, corrected against the data

The spec listed three items "on real data, from day one". Two of them are
derivable and one was wrong:

| Item | Derivable? | What the data actually says |
|---|---|---|
| The tariff bills at cost | yes | the rate card's `per_kg_cents` is 32 and the forwarder's `usd_per_kg` is 0.32 — the same number. Zero markup on every kilo. |
| A pickup charge is missing | **no** | there *is* a pickup row, charging a flat $150. The real problem is the opposite: it carries no per-kilo charge, so the freight on top of the collection is never charged. |
| Something for operations | yes | 54 lots are `LIVE` with `ends_at` in the past: auctions that closed and are still open for bids. |

So the queue is three derived checks, not three sentences typed into a Blade
file. A hand-written action list goes stale the moment the data changes, and
the previous one was already wrong before it was written.

## File structure

**Created:**

- `app/Services/OperationsOverview.php` — counters, activity, actions.
- `app/Filament/Pages/OperationsDashboard.php` — the page, replacing
  Filament's own dashboard on the operations panel.
- `resources/views/filament/pages/operations-dashboard.blade.php`
- `tests/Feature/OperationsOverviewTest.php`
- `tests/Feature/OperationsDashboardTest.php`

**Modified:**

- `app/Providers/Filament/AdminPanelProvider.php` — swap `Dashboard::class`
  for `OperationsDashboard::class`, and drop the Filament info widget.
- `docs/superpowers/specs/2026-10-21-opoku-dashboard-surfaces-design.md` —
  correct the required-action list to what the data says.

---

### Task 1: The overview service

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OperationsOverviewTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Bid;
use App\Models\Lot;
use App\Models\ShipmentMilestone;
use App\Models\User;
use App\Models\Wallet;
use App\Services\OperationsOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the auction side of the business looks like today.
 *
 * The counters and the queue are derived, so they cannot go stale. A page
 * with the findings typed into it says what was true the day it was written.
 */
class OperationsOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function lot(string $id, string $status = 'LIVE', bool $ended = false): Lot
    {
        return Lot::create([
            'id' => $id,
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => 'Vehicle '.$id,
            'city' => 'Newark',
            'photos' => [],
            'currency_code' => 'usd',
            'starting_bid' => 100000,
            'reserve_price' => 0,
            'bid_increment' => 1000,
            'starts_at' => now()->subDay(),
            'ends_at' => $ended ? now()->subHour() : now()->addDay(),
            'current_bid' => 200000,
            'status' => $status,
            'bid_count' => 0,
            'extensions' => 0,
            'deposit_bps' => 2000,
        ]);
    }

    public function test_it_counts_the_lots_on_sale(): void
    {
        $this->lot('lot_a');
        $this->lot('lot_b');
        $this->lot('lot_c', 'UPCOMING');

        $summary = app(OperationsOverview::class)->summary();

        $this->assertSame(2, $summary['lotsLive']);
        $this->assertSame(0, $summary['openBids']);
        $this->assertSame(0, $summary['moneyOwedCents']);
        $this->assertSame(0, $summary['heldOrDisputed']);
    }

    public function test_it_counts_the_open_bids(): void
    {
        $lot = $this->lot('lot_a');

        Bid::create([
            'id' => 'bid_a',
            'lot_id' => $lot->id,
            'bidder_id' => User::factory()->create(['role' => Role::Buyer])->id,
            'bidder_email' => 'buyer@example.test',
            'amount' => 200000,
            'currency_code' => 'usd',
            'status' => 'LEADING',
            'caused_extension' => false,
            'idempotency_key' => 'key-a',
        ]);

        $summary = app(OperationsOverview::class)->summary();

        $this->assertSame(1, $summary['openBids']);
    }

    public function test_it_sums_the_money_owed_to_us(): void
    {
        $lot = $this->lot('lot_a');
        $lot->update(['balance_due_usd' => 45000, 'highest_bidder_id' => 'someone']);

        $summary = app(OperationsOverview::class)->summary();

        $this->assertSame(45000, $summary['moneyOwedCents']);
    }

    public function test_it_counts_the_funds_held(): void
    {
        Wallet::create([
            'id' => 'wlt_a',
            'user_id' => User::factory()->create(['role' => Role::Buyer])->id,
            'currency_code' => 'usd',
            'balance_available' => 0,
            'balance_held' => 12000,
            'version' => 1,
        ]);

        $summary = app(OperationsOverview::class)->summary();

        $this->assertSame(1, $summary['heldOrDisputed']);
    }

    public function test_it_flags_the_rate_card_billing_at_or_below_cost(): void
    {
        $actions = app(OperationsOverview::class)->actions();

        $kinds = array_column($actions, 'kind');

        $this->assertContains('rate_card_at_cost', $kinds);
    }

    public function test_it_flags_a_pickup_row_with_no_per_kilo_charge(): void
    {
        $actions = app(OperationsOverview::class)->actions();

        $kinds = array_column($actions, 'kind');

        $this->assertContains('pickup_without_freight', $kinds);
    }

    public function test_it_flags_lots_that_closed_and_are_still_open_for_bids(): void
    {
        $this->lot('lot_open');
        $this->lot('lot_closed', 'LIVE', ended: true);

        $actions = app(OperationsOverview::class)->actions();

        $closed = collect($actions)->firstWhere('kind', 'closed_lot_still_open');

        $this->assertNotNull($closed);
        $this->assertStringContainsString('1', (string) $closed['tag']);
    }

    public function test_it_lists_activity_newest_first(): void
    {
        $lot = $this->lot('lot_a', 'LIVE', ended: true);

        $activity = app(OperationsOverview::class)->activity();

        $this->assertNotEmpty($activity);

        $times = array_column($activity, 'at');
        $sorted = $times;
        rsort($sorted);

        $this->assertSame($sorted, $times);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/OperationsOverviewTest.php`

Expected: FAIL — `App\Services\OperationsOverview` does not exist.

- [ ] **Step 3: Write the service**

Create `app/Services/OperationsOverview.php`:

```php
<?php

namespace App\Services;

use App\Models\Bid;
use App\Models\ClearanceTask;
use App\Models\FreightRate;
use App\Models\Lot;
use App\Models\RateCardRow;
use App\Models\ShipmentMilestone;
use App\Models\Wallet;
use Illuminate\Support\Collection;

/**
 * The auction side of the business, today.
 *
 * The counters and the queue are derived from the data so they cannot go
 * stale. The previous required-action list was three sentences typed into a
 * Blade file, and one of them was already wrong: the rate card does have a
 * pickup row — it just charges no freight on top of the collection.
 */
class OperationsOverview
{
    /**
     * @return array{lotsLive: int, openBids: int, moneyOwedCents: int, heldOrDisputed: int}
     */
    public function summary(): array
    {
        return [
            'lotsLive' => Lot::query()->where('status', 'LIVE')->count(),
            'openBids' => Bid::query()->where('status', 'LEADING')->count(),
            'moneyOwedCents' => (int) Lot::query()->where('balance_due_usd', '>', 0)->sum('balance_due_usd'),
            'heldOrDisputed' => Wallet::query()->where('balance_held', '>', 0)->count(),
        ];
    }

    /**
     * Everything that happened, newest first.
     *
     * @return array<int, array{at: string, title: string, detail: string}>
     */
    public function activity(int $limit = 12): array
    {
        $events = [];

        foreach (Bid::query()->with('lot')->latest('created_at')->limit($limit)->get() as $bid) {
            $events[] = [
                'at' => $bid->created_at->toIso8601String(),
                'title' => sprintf('%s bid on %s', $bid->bidder_email, $bid->lot?->title ?? 'a lot'),
                'detail' => '$'.number_format(((int) $bid->amount) / 100, 2),
            ];
        }

        foreach (Lot::query()->where('status', 'LIVE')->where('ends_at', '<', now())->latest('ends_at')->limit($limit)->get() as $lot) {
            $events[] = [
                'at' => $lot->ends_at->toIso8601String(),
                'title' => sprintf('%s closed', $lot->title),
                'detail' => 'at $'.number_format(((int) $lot->current_bid) / 100, 2),
            ];
        }

        foreach (ClearanceTask::query()->whereNotNull('completed_at')->latest('completed_at')->limit($limit)->get() as $task) {
            $events[] = [
                'at' => $task->completed_at->toIso8601String(),
                'title' => sprintf('%s cleared and receipted', $task->type),
                'detail' => '$'.number_format(((int) $task->cost) / 100, 2),
            ];
        }

        foreach (ShipmentMilestone::query()->latest('occurred_at')->limit($limit)->get() as $milestone) {
            $events[] = [
                'at' => $milestone->occurred_at->toIso8601String(),
                'title' => sprintf('Container recorded as %s', strtolower((string) $milestone->type)),
                'detail' => $milestone->notes ?? '',
            ];
        }

        usort($events, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($events, 0, $limit);
    }

    /**
     * What only an operator can move, derived.
     *
     * @return array<int, array{kind: string, title: string, detail: string, action: string, tag: string, tone: string}>
     */
    public function actions(): array
    {
        return [
            ...$this->rateCardAtCost(),
            ...$this->pickupWithoutFreight(),
            ...$this->closedLotsStillOpen(),
        ];
    }

    /**
     * A rate card row that charges no more than the forwarder costs.
     *
     * @return array<int, array{kind: string, title: string, detail: string, action: string, tag: string, tone: string}>
     */
    private function rateCardAtCost(): array
    {
        $rates = FreightRate::query()->where('active', true)->get();

        if ($rates->isEmpty()) {
            return [];
        }

        $actions = [];

        foreach (RateCardRow::query()->whereNotNull('per_kg_cents')->get() as $row) {
            foreach ($rates as $rate) {
                $costCents = (int) round(((float) $rate->usd_per_kg) * 100);

                if ((int) $row->per_kg_cents > $costCents) {
                    continue;
                }

                $actions[] = [
                    'kind' => 'rate_card_at_cost',
                    'title' => sprintf('The rate card bills at cost on %s', $row->destination),
                    'detail' => sprintf(
                        'The card charges %d c/kg and the forwarder charges %d c/kg. Every kilo we carry earns nothing.',
                        (int) $row->per_kg_cents,
                        $costCents,
                    ),
                    'action' => 'Set the retail rate, then the margin follows.',
                    'tag' => 'zero margin',
                    'tone' => 'bad',
                ];
            }
        }

        return $actions;
    }

    /**
     * A pickup row with no per-kilo charge: the collection is priced, the
     * freight on top of it is not.
     *
     * @return array<int, array{kind: string, title: string, detail: string, action: string, tag: string, tone: string}>
     */
    private function pickupWithoutFreight(): array
    {
        $rows = RateCardRow::query()
            ->where('intake_method', 'partner_pickup')
            ->whereNull('per_kg_cents')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        return $rows->map(fn (RateCardRow $row): array => [
            'kind' => 'pickup_without_freight',
            'title' => 'A pickup is quoted as a flat fee, so no freight is charged on top',
            'detail' => sprintf(
                'The pickup row charges %d c as a collection and nothing per kilo, while the forwarder charges the collection and the freight.',
                (int) $row->min_charge_cents,
            ),
            'action' => 'Add the per-kilo charge to the pickup row.',
            'tag' => 'under-priced',
            'tone' => 'warn',
        ])->all();
    }

    /**
     * Auctions that closed and are still open for bids.
     *
     * @return array<int, array{kind: string, title: string, detail: string, action: string, tag: string, tone: string}>
     */
    private function closedLotsStillOpen(): array
    {
        $count = Lot::query()
            ->where('status', 'LIVE')
            ->where('ends_at', '<', now())
            ->count();

        if ($count === 0) {
            return [];
        }

        return [[
            'kind' => 'closed_lot_still_open',
            'title' => sprintf('%d auctions have closed and are still open for bids', $count),
            'detail' => 'Their closing time has passed, so a bid on them now is a bid on something that is over.',
            'action' => 'Close them, or extend them with a stated reason.',
            'tag' => $count.' lots',
            'tone' => 'bad',
        ]];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/OperationsOverviewTest.php`

Expected: PASS, 8 tests. A failure on the rate-card check usually means the
seeder's rate card rows are missing — run
`php artisan db:seed --class=LogisticsRateSeeder --force`.

- [ ] **Step 5: Run the whole suite and commit**

Run: `php artisan test --compact`

Expected: PASS, 497 tests.

```bash
git add app/Services/OperationsOverview.php tests/Feature/OperationsOverviewTest.php
git commit -m "feat(admin): the operations overview, derived from the data"
```

---

### Task 2: The console page

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OperationsDashboardTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The operations console replaces Filament's own dashboard.
 *
 * Filament's dashboard is a greeting, a form and a panel that says
 * "Filament v5.10.2" with links to Filament's documentation. None of that
 * tells an operator what is happening.
 */
class OperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_console_is_the_front_door_of_the_operations_panel(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Operations')
            ->assertSee('Required action');
    }

    public function test_it_does_not_advertise_filament(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('Filament v5');
    }

    public function test_a_buyer_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Buyer]), 'admin')
            ->get('/admin')
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/OperationsDashboardTest.php`

Expected: FAIL — the page still renders Filament's dashboard.

- [ ] **Step 3: Write the page**

Create `app/Filament/Pages/OperationsDashboard.php`:

```php
<?php

namespace App\Filament\Pages;

use App\Services\OperationsOverview;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class OperationsDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $navigationGroup = 'Marketplace';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Operations';

    /**
     * The console's front door is the console's own URL.
     */
    protected static ?string $slug = '/';

    protected string $view = 'filament.pages.operations-dashboard';

    public function getViewData(): array
    {
        $overview = app(OperationsOverview::class);

        return [
            'summary' => $overview->summary(),
            'activity' => $overview->activity(),
            'actions' => $overview->actions(),
        ];
    }
}
```

- [ ] **Step 4: Write the view**

Create `resources/views/filament/pages/operations-dashboard.blade.php`:

```blade
{{-- The operations console.

     The same card system as the freight console, because it is the same
     product. The data comes from OperationsOverview, which is tested without
     a browser; this file is a view over it. --}}
<x-filament-panels::page>
    @php
        $money = fn ($cents) => '$' . number_format(((int) $cents) / 100, 2);
    @endphp

    <div class="grid tiles-4">
        @foreach ([
            ['Lots live', $summary['lotsLive'], false],
            ['Open bids', $summary['openBids'], false],
            ['Money owed to us', $money($summary['moneyOwedCents']), false],
            ['Funds held', $summary['heldOrDisputed'], $summary['heldOrDisputed'] > 0],
        ] as [$label, $value, $alert])
            <div class="card">
                <div class="tile">
                    <span class="disc {{ $alert ? 'alt' : '' }}">{{ $alert ? '!' : '◈' }}</span>
                    <div>
                        <div class="label">{{ $label }}</div>
                        <div class="fig tnum">{{ $value }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid split" style="margin-top: 1rem;">
        <section class="card">
            <h2>Recent activity</h2>
            <p class="sub">Everything that happened, newest first.</p>

            <ul class="timeline">
                @forelse ($activity as $event)
                    <li>
                        <span class="rail"><span class="node"></span></span>
                        <div>
                            <div class="what">{{ $event['title'] }}</div>
                            @if ($event['detail'])
                                <div class="meta">{{ $event['detail'] }}</div>
                            @endif
                        </div>
                        <span class="when">{{ \Illuminate\Support\Carbon::parse($event['at'])->toFormattedDateString() }}</span>
                    </li>
                @empty
                    <p class="muted">Nothing has happened yet.</p>
                @endforelse
            </ul>
        </section>

        <section class="card">
            <h2>Required action</h2>
            <p class="sub">What is waiting on a person, and who.</p>

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
</x-filament-panels::page>
```

- [ ] **Step 5: Point the panel at it**

In `app/Providers/Filament/AdminPanelProvider.php`:

- replace `use Filament\Pages\Dashboard;` with
  `use App\Filament\Pages\OperationsDashboard;`
- replace `->pages([Dashboard::class])` with
  `->pages([OperationsDashboard::class])`
- delete the `FilamentInfoWidget` from the widgets list, and its import. It
  renders a card that says "Filament v5.10.2" with links to Filament's
  documentation and GitHub — it is not an operations tool.

The widgets list becomes:

```php
            ->widgets([
                AccountWidget::class,
            ])
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/OperationsDashboardTest.php`

Expected: PASS, 3 tests.

- [ ] **Step 7: Run the whole suite, format and commit**

Run: `php artisan test --compact` then `vendor/bin/pint --dirty --format agent`

Expected: PASS, 500 tests.

```bash
git add app/Filament/Pages/OperationsDashboard.php \
  resources/views/filament/pages/operations-dashboard.blade.php \
  app/Providers/Filament/AdminPanelProvider.php tests/Feature/OperationsDashboardTest.php
git commit -m "feat(admin): the operations console, in our own cards"
```

---

### Task 3: Correct the spec, and look at it

- [ ] **Step 1: Correct the spec**

In `docs/superpowers/specs/2026-10-21-opoku-dashboard-surfaces-design.md`, §4
lists the required-action items as "the tariff markup (D-1 — the rate card
bills at cost), the missing pickup charge, and the second forwarder". Two of
those are wrong against the data: there is a pickup row (it just carries no
per-kilo charge), and the second forwarder belongs to the freight console, not
this one. Replace that sentence with:

```
**Required action, derived from the data, not written by hand:** the rate
card's per-kilo charge against the forwarder's cost, a pickup row that
prices the collection but not the freight on top of it, and auctions that
closed and are still open for bids. A hand-written action list goes stale
the moment the data changes, and the previous one was wrong before it was
written.
```

- [ ] **Step 2: Build the assets and look**

Run: `npm run build`, then open `http://127.0.0.1:8126/admin` and check
the same list as the freight console: grey canvas, white cards, circular
icon tiles, mono figures, a timeline, and a required-action queue with a
short chip and a next action. Take a screenshot.

- [ ] **Step 3: Commit**

```bash
git add docs/superpowers/specs/2026-10-21-opoku-dashboard-surfaces-design.md
git commit -m "docs: the operations console's queue, corrected against the data"
```
