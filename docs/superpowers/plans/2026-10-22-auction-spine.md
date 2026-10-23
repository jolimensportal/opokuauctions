# The Auction Spine — Phase 1: the auction becomes real

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every number the storefront and the consoles show about an auction — the bid count, the current bid, the winner, the live count — is backed by a record, and an auction whose time is up closes itself.

**Architecture:** The service layer is already built and correct: `LotsService::placeBid()` writes bid rows and maintains the lot, `LotsService::finalizeLot()` closes an expired auction, `WalletService` holds/captures/releases money, and the `lot` table already carries the money lifecycle (`balance_due_usd`, `balance_settled_at`, `deposit_bps`). What is missing is the *wiring*: nothing ever calls `finalizeLot`, nothing ever holds funds for a bid, and the demo data paints a bid count and a current bid without a single row in the `bid` table. This phase fixes the first two of those three, which is what makes Phase 2 (a won lot becomes a paid lot) and Phase 3 (a paid lot joins a container) possible.

**Tech Stack:** Laravel 12, PHP 8.3, Eloquent, PostgreSQL 15 (port 5434), PHPUnit.

---

## Why this phase, and not something prettier

The three consoles were finished on 2026-10-21 and verified in a browser. They are honest, and what they show is that the middle of the product is missing:

| Link | State today |
|---|---|
| bid | ✅ `LotsService::placeBid()` — real, tested by hand only |
| close when time is up | ⚠️ `finalizeLot()` exists and **nothing calls it** outside a spike script |
| buyer pays for the lot won | ❌ no checkout; `WalletService::hold/capture` is called by nothing but a spike script |
| lot marked paid | ❌ nothing sets `balance_settled_at` |
| lot put on a container | ❌ **no code writes `shipment_lots`** — only a seeder does |
| milestones, clearance | ✅ `ShipmentMilestoneRecorder`, `ClearanceService` |
| buyer sees their car | ✅ `CustomerFreight` reads the link |

Measured on the dev database at the time of writing:

- **61 lots are `LIVE` with `ends_at` in the past.** The storefront is selling 61 auctions that closed long ago; the console's "163 lots live" is 102 real and 61 dead.
- **The `bid` table is empty.** The seeders write `current_bid` and `bid_count` as decoration — a lot shows "9 bids, $2,150" with no bid, no bidder, no record. 54 of the 61 dead lots have a painted bid count; only 1 has a `highest_bidder_id`.
- **Closing them naively would create 10 phantom sales.** `finalizeLot()` sells on `bid_count > 0 && current_bid >= reserve_price`, and 11 lots meet that bar while only 1 has a recorded winner. A sale to nobody is worse than a lot that stays open.
- **`finalizeLot()` and `placeBid()` have no tests at all.** `tests/` contains no file that mentions either. The money-critical path of an auction house is unguarded.

So the order is: make the bids real → then close the auctions → then, in later phases, take the money and put the car on a container.

---

## File Structure

- `app/Services/LotsService.php` — modified. One guard added: a lot with a reserve-met bid but no recorded bidder must not be sold.
- `app/Console/Commands/CloseExpiredLots.php` — **new**. The command that closes auctions whose time is up.
- `bootstrap/app.php` — modified. Schedules the command every minute, next to the parcel sweep that already has a scheduler.
- `database/seeders/DemoBidSeeder.php` — **new**. Turns the painted bid counts into real bid rows with real bidders and funded wallets.
- `database/seeders/DatabaseSeeder.php` — modified. Runs the new seeder after the inventory seeders.
- `tests/Feature/LotsServiceTest.php` — **new**. The auction lifecycle's first tests.
- `tests/Feature/CloseExpiredLotsCommandTest.php` — **new**. The command and its guard.
- `tests/Feature/DemoBidSeederTest.php` — **new**. The seed's internal consistency.

---

### Task 1: The auction lifecycle gets its tests, and a sale to nobody becomes impossible

**Files:**
- Create: `tests/Feature/LotsServiceTest.php`
- Modify: `app/Services/LotsService.php:160-199` (`finalizeLot`)

- [x] **Step 1: Write the failing test for a bid**

Create `tests/Feature/LotsServiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Lot;
use App\Services\LotsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class LotsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function liveLot(array $overrides = []): Lot
    {
        $lot = Lot::create(array_merge([
            'kind' => 'vehicle',
            'provenance' => 'clean_title',
            'title' => '2018 Audi RS4',
            'city' => 'Newark',
            'condition' => 'clean_title',
            'currency_code' => 'usd',
            'starting_bid' => 145_000,
            'reserve_price' => 190_000,
            'bid_increment' => 5_000,
            'status' => 'LIVE',
            'current_bid' => 0,
            'bid_count' => 0,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ], $overrides));

        return $lot->fresh();
    }

    public function test_a_bid_is_recorded_and_becomes_the_leading_bid(): void
    {
        $lot = $this->liveLot();

        $result = app(LotsService::class)->placeBid([
            'lot_id' => $lot->id,
            'bidder_id' => '7',
            'bidder_email' => 'buyer@example.test',
            'amount' => 145_000,
        ]);

        $this->assertSame(145_000, $result['lot']['current_bid']);
        $this->assertSame(1, $result['lot']['bid_count']);
        $this->assertSame('7', $result['lot']['highest_bidder_id']);

        $this->assertSame(1, Bid::where('lot_id', $lot->id)->count());
        $this->assertSame('ACCEPTED', Bid::where('lot_id', $lot->id)->first()->status);
    }

    public function test_a_higher_bid_demotes_the_previous_leader_but_keeps_the_record(): void
    {
        $lot = $this->liveLot();
        $service = app(LotsService::class);

        $service->placeBid([
            'lot_id' => $lot->id, 'bidder_id' => '7',
            'bidder_email' => 'seven@example.test', 'amount' => 145_000,
        ]);
        $service->placeBid([
            'lot_id' => $lot->id, 'bidder_id' => '8',
            'bidder_email' => 'eight@example.test', 'amount' => 150_000,
        ]);

        $bids = Bid::where('lot_id', $lot->id)->orderBy('created_at')->get();

        $this->assertCount(2, $bids);
        $this->assertSame('OUTBID', $bids[0]->status);
        $this->assertSame('ACCEPTED', $bids[1]->status);
        $this->assertSame('8', $lot->fresh()->highest_bidder_id);
    }

    public function test_a_closed_lot_refuses_bids(): void
    {
        $lot = $this->liveLot(['status' => 'SOLD']);

        $this->expectException(RuntimeException::class);

        app(LotsService::class)->placeBid([
            'lot_id' => $lot->id, 'bidder_id' => '7',
            'bidder_email' => 'seven@example.test', 'amount' => 145_000,
        ]);
    }

    public function test_an_expired_auction_with_a_reserve_met_bid_is_sold_to_the_leader(): void
    {
        $lot = $this->liveLot(['ends_at' => now()->subMinute()]);
        app(LotsService::class)->placeBid([
            'lot_id' => $lot->id, 'bidder_id' => '7',
            'bidder_email' => 'seven@example.test', 'amount' => 195_000,
        ]);
        $lot->update(['ends_at' => now()->subMinute()]);

        $result = app(LotsService::class)->finalizeLot($lot->id);

        $this->assertSame('SOLD', $result['status']);
        $this->assertSame('7', $result['winner_id']);
        $this->assertSame('SOLD', $lot->fresh()->status);
    }

    public function test_an_expired_auction_below_the_reserve_is_not_sold(): void
    {
        $lot = $this->liveLot(['ends_at' => now()->subMinute()]);

        $result = app(LotsService::class)->finalizeLot($lot->id);

        $this->assertSame('RESERVE_NOT_MET', $result['status']);
        $this->assertNull($result['winner_id']);
    }

    public function test_an_auction_that_has_not_expired_cannot_be_closed(): void
    {
        $lot = $this->liveLot();

        $this->expectException(RuntimeException::class);

        app(LotsService::class)->finalizeLot($lot->id);
    }

    public function test_closing_twice_changes_nothing(): void
    {
        $lot = $this->liveLot(['ends_at' => now()->subMinute()]);
        app(LotsService::class)->placeBid([
            'lot_id' => $lot->id, 'bidder_id' => '7',
            'bidder_email' => 'seven@example.test', 'amount' => 195_000,
        ]);
        $lot->update(['ends_at' => now()->subMinute()]);

        app(LotsService::class)->finalizeLot($lot->id);
        $second = app(LotsService::class)->finalizeLot($lot->id);

        $this->assertSame('SOLD', $second['status']);
        $this->assertNull($second['winner_id']);
        $this->assertSame('SOLD', $lot->fresh()->status);
    }

    public function test_a_lot_with_a_reserve_met_bid_but_no_bidder_is_never_sold(): void
    {
        // The demo data used to look like this: a bid count and a current bid
        // with no bid row and no bidder. Selling it would record a sale to
        // nobody, and the money panels would then chase a customer who does
        // not exist.
        $lot = $this->liveLot([
            'ends_at' => now()->subMinute(),
            'current_bid' => 195_000,
            'bid_count' => 4,
            'highest_bidder_id' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no recorded bidder');

        app(LotsService::class)->finalizeLot($lot->id);
    }
}
```

- [x] **Step 2: Run the test to see it fail**

Run: `php artisan test --compact tests/Feature/LotsServiceTest.php`

Expected: the first bid test fails if `Lot::create` cannot fill `starts_at`/`ends_at` (they are in `$fillable` — verify), and the last test fails with "no such exception" because `finalizeLot` currently sells a lot with no bidder.

- [x] **Step 3: Add the guard to `finalizeLot`**

In `app/Services/LotsService.php`, inside `finalizeLot()`, replace:

```php
            $sold = $lot->bid_count > 0 && $lot->current_bid >= $lot->reserve_price;
            $status = $sold ? 'SOLD' : 'RESERVE_NOT_MET';
```

with:

```php
            $reserveMet = $lot->bid_count > 0 && $lot->current_bid >= $lot->reserve_price;

            // A sale needs a buyer. A lot can carry a bid count and a current
            // bid with no bid row behind it - the demo data used to, and the
            // figure is a number, not a record. Selling that would record a
            // sale to nobody, and the money panels would then chase a
            // customer who does not exist. It stays open instead, and the
            // close command reports it for a human to look at.
            if ($reserveMet && blank($lot->highest_bidder_id)) {
                throw new RuntimeException("Lot {$lotId} has a reserve-met bid but no recorded bidder");
            }

            $sold = $reserveMet;
            $status = $sold ? 'SOLD' : 'RESERVE_NOT_MET';
```

- [x] **Step 4: Run the tests to see them pass**

Run: `php artisan test --compact tests/Feature/LotsServiceTest.php`

Expected: all tests pass. If `Lot::create` rejects `starts_at`/`ends_at`, use `forceFill` + `save()` instead of `create`.

- [x] **Step 5: Commit**

```bash
git add tests/Feature/LotsServiceTest.php app/Services/LotsService.php
git commit -m "test(lots): cover the auction lifecycle, and never sell to nobody"
```

---

### Task 2: The demo's bids become records

**Files:**
- Create: `database/seeders/DemoBidSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `tests/Feature/DemoBidSeederTest.php`

- [x] **Step 1: Write the failing test**

Create `tests/Feature/DemoBidSeederTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Bid;
use App\Models\Lot;
use App\Models\User;
use App\Services\WalletService;
use Database\Seeders\DemoBidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoBidSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_painted_bid_count_becomes_a_real_bid(): void
    {
        $lot = Lot::create([
            'kind' => 'vehicle',
            'provenance' => 'clean_title',
            'title' => '2018 Audi RS4',
            'city' => 'Newark',
            'condition' => 'clean_title',
            'currency_code' => 'usd',
            'starting_bid' => 145_000,
            'reserve_price' => 190_000,
            'bid_increment' => 5_000,
            'status' => 'LIVE',
            'current_bid' => 0,
            'bid_count' => 0,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        // The intent the inventory seeder writes on the lot.
        $lot->update(['current_bid' => 165_000, 'bid_count' => 4]);

        $this->seed(DemoBidSeeder::class);

        $this->assertSame(4, Bid::where('lot_id', $lot->id)->count());
        $this->assertSame(165_000, $lot->fresh()->current_bid);
        $this->assertNotNull($lot->fresh()->highest_bidder_id);
    }

    public function test_the_bidders_are_real_users_with_money_behind_them(): void
    {
        $lot = Lot::create([
            'kind' => 'vehicle', 'provenance' => 'clean_title', 'title' => '2018 Audi RS4',
            'city' => 'Newark', 'condition' => 'clean_title', 'currency_code' => 'usd',
            'starting_bid' => 145_000, 'reserve_price' => 190_000, 'bid_increment' => 5_000,
            'status' => 'LIVE', 'current_bid' => 0, 'bid_count' => 0,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);
        $lot->update(['current_bid' => 165_000, 'bid_count' => 4]);

        $this->seed(DemoBidSeeder::class);

        $bidder = User::find($lot->fresh()->highest_bidder_id);

        $this->assertNotNull($bidder);
        $this->assertSame('buyer', $bidder->role);

        // The money is real: the wallet exists, it has a balance, and it
        // reconciles against its own ledger.
        $wallet = app(WalletService::class)->reconcile((string) $bidder->id, 'usd');

        $this->assertTrue($wallet['balanced'], $wallet['reason'] ?? 'wallet drifted');
    }

    public function test_a_lot_with_no_painted_bids_is_left_alone(): void
    {
        $lot = Lot::create([
            'kind' => 'vehicle', 'provenance' => 'clean_title', 'title' => '2018 Audi RS4',
            'city' => 'Newark', 'condition' => 'clean_title', 'currency_code' => 'usd',
            'starting_bid' => 145_000, 'reserve_price' => 190_000, 'bid_increment' => 5_000,
            'status' => 'LIVE', 'current_bid' => 0, 'bid_count' => 0,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);

        $this->seed(DemoBidSeeder::class);

        $this->assertSame(0, Bid::where('lot_id', $lot->id)->count());
        $this->assertNull($lot->fresh()->highest_bidder_id);
    }
}
```

- [x] **Step 2: Run the test to see it fail**

Run: `php artisan test --compact tests/Feature/DemoBidSeederTest.php`

Expected: FAIL — `DemoBidSeeder` does not exist.

- [x] **Step 3: Write the seeder**

Create `database/seeders/DemoBidSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Bid;
use App\Models\Lot;
use App\Models\User;
use App\Services\LotsService;
use App\Services\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The bids the inventory seeders paint, made real.
 *
 * The inventory seeders write a bid count and a current bid straight onto the
 * lot, so a lot reads "9 bids, $2,150" on the storefront. There was no bid
 * row behind any of it: the bid table was empty, every lot had a current bid
 * and no bidder, and the only winner in the whole database was one lot tied
 * to the demo buyer by hand.
 *
 * A number on a page is not a record. This seeder turns the painted intent
 * into bids: real rows, real bidders, real wallets with money in them. It
 * goes through LotsService::placeBid() rather than writing the columns
 * itself, so the bid rows and the lot's columns cannot drift apart.
 */
class DemoBidSeeder extends Seeder
{
    /** Bidders used for the demo history. Staff accounts do not bid. */
    private const BIDDER_EMAILS = [
        'bidder.one@opokuauctions.test',
        'bidder.two@opokuauctions.test',
        'bidder.three@opokuauctions.test',
    ];

    /** How much each demo bidder is given to bid with, in cents. */
    private const FUNDING_CENTS = 50_000_000;

    public function run(): void
    {
        $lots = Lot::where('status', 'LIVE')
            ->where('bid_count', '>', 0)
            ->whereDoesntHave('bids', fn ($query) => $query)
            ->get();

        if ($lots->isEmpty()) {
            return;
        }

        $bidders = $this->bidders();
        $service = app(LotsService::class);
        $wallet = app(WalletService::class);

        foreach ($lots as $lot) {
            $this->materialiseBids($lot, $bidders, $service, $wallet);
        }
    }

    /**
     * The demo buyers, funded once.
     *
     * @return array<int, User>
     */
    private function bidders(): array
    {
        $bidders = [];

        foreach (self::BIDDER_EMAILS as $email) {
            $bidder = User::where('email', $email)->first();

            if (! $bidder) {
                $bidder = User::create([
                    'name' => 'Demo bidder '.str($email)->after('bidder.')->before('@')->title(),
                    'email' => $email,
                    'password' => 'opoku-demo-bidder',
                    'role' => 'buyer',
                    'country_code' => 'GH',
                ]);
            }

            $wallet->ensureWallet((string) $bidder->id, 'usd');
            $wallet->deposit((string) $bidder->id, self::FUNDING_CENTS, 'usd', ['id' => 'demo-funding']);

            $bidders[] = $bidder;
        }

        return $bidders;
    }

    /**
     * Write the lot's painted bid history as real bids.
     *
     * @param  array<int, User>  $bidders
     */
    private function materialiseBids(Lot $lot, array $bidders, LotsService $service, WalletService $wallet): void
    {
        $target = (int) $lot->current_bid;
        $count = (int) $lot->bid_count;
        $increment = max(1, (int) $lot->bid_increment);

        // The amount each bid must be at least, working up to the painted
        // current bid. The last bid lands exactly on it.
        $amounts = [];
        $amount = (int) $lot->starting_bid;

        for ($i = 0; $i < $count; $i++) {
            $remaining = $count - $i - 1;
            $next = $target - ($remaining * $increment);

            $amount = max($amount, $next);
            $amounts[] = $amount;
        }

        // The clock is moved forward while the history is written: placeBid
        // refuses a lot whose time is up, and the point of this seeder is to
        // stage history for lots that have since closed. The original close
        // time is put back afterwards.
        $originalEndsAt = $lot->ends_at;

        DB::transaction(function () use ($lot, $amounts, $bidders, $service, &$originalEndsAt) {
            $lot->update(['ends_at' => Carbon::now()->addDays(30)]);

            foreach ($amounts as $index => $amount) {
                $bidder = $bidders[$index % count($bidders)];

                $service->placeBid([
                    'lot_id' => $lot->id,
                    'bidder_id' => (string) $bidder->id,
                    'bidder_email' => (string) $bidder->email,
                    'amount' => $amount,
                    'idempotency_key' => 'demo-bid-'.$lot->id.'-'.$index,
                ]);
            }

            $lot->update(['ends_at' => $originalEndsAt]);
        });
    }
}
```

> If `Lot` has no `bids` relation, replace `whereDoesntHave('bids', ...)` with a `whereNotExists` on the `bid` table:
> `->whereNotExists(fn ($query) => $query->selectRaw('1')->from('bid')->whereColumn('bid.lot_id', 'lot.id'))`.

- [x] **Step 4: Run the test to see it pass**

Run: `php artisan test --compact tests/Feature/DemoBidSeederTest.php`

Expected: PASS. If `WalletService::deposit` rejects a repeat deposit for the same reference, use a per-bidder reference: `['id' => 'demo-funding-'.$bidder->id]`.

- [x] **Step 5: Register the seeder**

In `database/seeders/DatabaseSeeder.php`, add `DemoBidSeeder::class` **after** the inventory seeders and **after** `DemoBuyerFreightSeeder` is not required — the bid seeder only needs lots to exist. Order:

```php
$this->call([
    // ...existing seeders...
    DemoBidSeeder::class,
]);
```

- [x] **Step 6: Commit**

```bash
git add database/seeders/DemoBidSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/DemoBidSeederTest.php
git commit -m "feat(seed): make the demo's bid history real records"
```

---

### Task 3: Expired auctions close themselves

**Files:**
- Create: `app/Console/Commands/CloseExpiredLots.php`
- Modify: `bootstrap/app.php:29-33`
- Create: `tests/Feature/CloseExpiredLotsCommandTest.php`

- [x] **Step 1: Write the failing test**

Create `tests/Feature/CloseExpiredLotsCommandTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Lot;
use App\Services\LotsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseExpiredLotsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function expiredLot(array $overrides = []): Lot
    {
        $lot = Lot::create(array_merge([
            'kind' => 'vehicle', 'provenance' => 'clean_title', 'title' => '2018 Audi RS4',
            'city' => 'Newark', 'condition' => 'clean_title', 'currency_code' => 'usd',
            'starting_bid' => 145_000, 'reserve_price' => 190_000, 'bid_increment' => 5_000,
            'status' => 'LIVE', 'current_bid' => 0, 'bid_count' => 0,
            'starts_at' => now()->subDays(3), 'ends_at' => now()->subMinute(),
        ], $overrides));

        return $lot->fresh();
    }

    public function test_an_expired_auction_with_a_winner_is_closed_as_sold(): void
    {
        $lot = $this->expiredLot();
        app(LotsService::class)->placeBid([
            'lot_id' => $lot->id, 'bidder_id' => '7',
            'bidder_email' => 'seven@example.test', 'amount' => 195_000,
        ]);
        $lot->update(['ends_at' => now()->subMinute()]);

        $this->artisan('lots:close-expired')
            ->expectsOutputToContain('sold 1')
            ->assertSuccessful();

        $this->assertSame('SOLD', $lot->fresh()->status);
    }

    public function test_a_live_auction_is_left_alone(): void
    {
        $lot = $this->expiredLot(['ends_at' => now()->addDay()]);

        $this->artisan('lots:close-expired')->assertSuccessful();

        $this->assertSame('LIVE', $lot->fresh()->status);
    }

    public function test_a_lot_with_a_bid_but_no_bidder_is_reported_and_left_open(): void
    {
        $lot = $this->expiredLot(['current_bid' => 195_000, 'bid_count' => 4]);

        $this->artisan('lots:close-expired')
            ->expectsOutputToContain('needs review 1')
            ->assertSuccessful();

        $this->assertSame('LIVE', $lot->fresh()->status);
    }

    public function test_running_twice_changes_nothing(): void
    {
        $lot = $this->expiredLot();
        app(LotsService::class)->placeBid([
            'lot_id' => $lot->id, 'bidder_id' => '7',
            'bidder_email' => 'seven@example.test', 'amount' => 195_000,
        ]);
        $lot->update(['ends_at' => now()->subMinute()]);

        $this->artisan('lots:close-expired')->assertSuccessful();
        $this->artisan('lots:close-expired')
            ->expectsOutputToContain('sold 0')
            ->assertSuccessful();
    }
}
```

- [x] **Step 2: Run the test to see it fail**

Run: `php artisan test --compact tests/Feature/CloseExpiredLotsCommandTest.php`

Expected: FAIL — the command `lots:close-expired` does not exist.

- [x] **Step 3: Write the command**

Create `app/Console/Commands/CloseExpiredLots.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\LotsService;
use Illuminate\Console\Command;
use RuntimeException;

class CloseExpiredLots extends Command
{
    protected $signature = 'lots:close-expired {--limit=200 : How many expired lots to close in one pass}';

    protected $description = 'Close auctions whose time is up: sold when the reserve is met, otherwise not met';

    public function handle(LotsService $lots): int
    {
        $expired = $lots->findExpiredLiveLots((int) $this->option('limit'));

        $sold = 0;
        $notMet = 0;
        $needsReview = [];

        foreach ($expired as $lot) {
            try {
                $result = $lots->finalizeLot($lot->id);
            } catch (RuntimeException $exception) {
                // A lot with a reserve-met bid and no recorded bidder is data
                // that has to be looked at by a person. It stays open rather
                // than being closed to a sale nobody made.
                $needsReview[] = $lot->id.': '.$exception->getMessage();
                continue;
            }

            if ($result['status'] === 'SOLD') {
                $sold++;
            } else {
                $notMet++;
            }
        }

        $this->info("sold {$sold}, reserve not met {$notMet}, needs review ".count($needsReview));

        foreach ($needsReview as $line) {
            $this->warn($line);
        }

        return self::SUCCESS;
    }
}
```

- [x] **Step 4: Run the test to see it pass**

Run: `php artisan test --compact tests/Feature/CloseExpiredLotsCommandTest.php`

Expected: PASS.

- [x] **Step 5: Schedule it**

In `bootstrap/app.php`, inside `->withSchedule(...)`, add next to the parcel sweep:

```php
        // An auction whose time is up has to close itself. The service that
        // closes one existed from the start; nothing called it, so every
        // expired lot stayed LIVE — which meant the storefront was selling
        // auctions that had already closed and the console's live count was
        // wrong. Every minute, because an auction that closes late is an
        // auction that shows a dead countdown.
        $schedule->command('lots:close-expired')->everyMinute();
```

- [x] **Step 6: Run the whole suite**

Run: `php artisan test --compact`

Expected: all tests pass.

- [x] **Step 7: Run the command against the dev database and look at the result**

Run: `php artisan lots:close-expired`

Expected: a line like `sold 11, reserve not met 50, needs review 0` — **after** the demo bid seeder has run (Task 2), so that every reserve-met lot has a recorded bidder. If `needs review` is not 0, the seed still has painted bids with no bidder: run `php artisan db:seed --class=DemoBidSeeder --force` and try again.

- [x] **Step 8: Verify the console tells the truth now**

In the browser, open `/admin` and check the "Lots live" tile. It should show the number of lots that are genuinely live, and the "closed lots still open" action should be gone from the Required action panel.

- [x] **Step 9: Commit**

```bash
git add app/Console/Commands/CloseExpiredLots.php bootstrap/app.php tests/Feature/CloseExpiredLotsCommandTest.php
git commit -m "feat(lots): close expired auctions every minute"
```

---

## Execution notes

Written after the three tasks ran, so the record matches what was built rather
than what was planned.

- **Task 1** — as written. The guard lives in `finalizeLot()`: a reserve-met
  lot with no recorded bidder throws, stays `LIVE`, and is reported. Commit
  `b3d18a1`.
- **Task 2** — four corrections:
  - Painted lots are found with `whereNotExists` against the `bid` table; the
    `Lot` model has no `bids()` relation to query through.
  - `WalletService::reconcile()` returns `ok`, not `balanced`.
  - Funding is once-only (a wallet whose available balance is zero), so a
    second seed cannot double the demo money.
  - The amount series is capped at what the increment can actually produce.
    `lot_demo_02` was painted with 14 bids, $100 increments and a $650 spread
    from $320,000 to $385,000; fourteen bids cannot land there. Seven real
    bids are written and `bid_count` is rebuilt from the rows. Covered by
    `test_a_painted_count_the_increment_cannot_reach_is_capped`.
  - Test gotcha: bids written in the same second cannot be ordered by
    `created_at`; order by `amount` or by the `ACCEPTED` status.
- **Task 3** — two corrections:
  - The test as written placed a bid on a lot whose `ends_at` was already in
    the past, which `placeBid()` refuses. The test now places the bid while
    the lot is live, then moves `ends_at` into the past.
  - The command reports one line: `sold N, reserve not met N, needs review N`.
- **Dev run** (seeder first, then the closer): `sold 16, reserve not met 79,
  needs review 0`. Zero expired lots remain `LIVE`. `/admin` shows 68 live and
  the "closed lots still open" action is gone, checked in a browser.
- **Suite**: 530 tests, 1,700 assertions, all green.

## What this phase deliberately does not do

- **No checkout, no payment, no capture.** That is Phase 2. The wallet can already hold, release and capture; what is missing is the flow that calls it for a won lot, and the moment in the buyer's life when they are asked for money is a product decision, not a code detail.
- **No container assignment.** That is Phase 3: a shipped lot joined to a container, which is what `shipment_lots` is for and what no code writes today.
- **The 43 lots that miss the reserve are closed, not re-listed.** Re-opening them is a commercial decision (a missed reserve usually means the price was wrong), and it is a separate question from closing the ones whose time is up.
- **The console's resource screens still wear Filament's list style.** Only the three dashboards are re-skinned. That is cosmetic and it can wait until the spine works.

## The two decisions this phase needs from the user

1. **The retail price for the Tema rate card.** The card bills 32 c/kg and the forwarder charges $0.32/kg, so every kilo earns nothing. The console says so in its Required action panel. The markup cannot be derived — it has to be decided.
2. **What happens to the lots that close without meeting the reserve.** Closed and left alone, or re-listed with a fresh window?
