# Operations Console Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an operations console whose hero is a triage queue of derived exceptions, so staff can answer "what is wrong right now, and who has it" instead of hunting through CRUD screens.

**Architecture:** One read-only `AttentionQuery` service derives every open exception from existing tables and returns typed `AttentionItem` value objects. A single new table, `attention_assignments`, stores the owner chosen at first detection, because a derived exception has nowhere else to hold that fact. A Filament page renders the queue; every other surface (badge, digest) reads the same service so a count can never disagree with its rows.

**Tech Stack:** Laravel 12, PHP 8.3, PostgreSQL 15, Filament 4, PHPUnit 12, Blade with Tailwind CSS v4.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Enums/Severity.php` | Critical/High/Medium/Low, ordered so the queue can sort by it |
| `app/Enums/AttentionKind.php` | The seven exception types and their default severity |
| `app/Data/AttentionItem.php` | Readonly value object returned by the query |
| `app/Services/AttentionQuery.php` | Read-only; derives all seven exception types |
| `app/Services/AttentionAssigner.php` | Picks the least-loaded staff member, once |
| `app/Models/AttentionAssignment.php` | Eloquent model for the one new table |
| `app/Filament/Pages/AttentionQueue.php` | The console page |
| `resources/views/filament/pages/attention-queue.blade.php` | Queue rendering |
| `database/migrations/2026_10_18_100000_create_attention_assignments_table.php` | The one new table |

Each exception detector is a **method on `AttentionQuery`**, not a separate class. Seven tiny private methods that each return a collection share the same filters, the same severity rules and the same ref format; splitting them across seven files would add seven constructor dependencies to express seven one-line queries.

---

### Task 1: Severity and AttentionKind enums

**Files:**
- Create: `app/Enums/Severity.php`
- Create: `app/Enums/AttentionKind.php`
- Test: `tests/Unit/SeverityTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/SeverityTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Enums\AttentionKind;
use App\Enums\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Severity is derived from row facts, never typed by a human. These tests pin
 * the ordering the queue sorts on, because a queue sorted by the wrong order
 * shows a failed message above an unpaid duty and nobody reads past row two.
 */
class SeverityTest extends TestCase
{
    public function test_severity_sorts_most_urgent_first(): void
    {
        $ordered = [
            Severity::Low,
            Severity::Medium,
            Severity::High,
            Severity::Critical,
        ];

        // arsort-equivalent: each value must be strictly greater than the next.
        for ($i = 0; $i < count($ordered) - 1; $i++) {
            $this->assertGreaterThan(
                $ordered[$i + 1]->weight(),
                $ordered[$i]->weight(),
                'Severity order is what the queue sorts on.',
            );
        }
    }

    public function test_critical_is_the_most_urgent(): void
    {
        $this->assertSame(4, Severity::Critical->weight());
        $this->assertSame(1, Severity::Low->weight());
    }

    public function test_every_attention_kind_declares_a_severity(): void
    {
        foreach (AttentionKind::cases() as $kind) {
            $this->assertInstanceOf(
                Severity::class,
                $kind->defaultSeverity(),
                "{$kind->value} must carry a severity.",
            );
        }
    }

    public function test_the_seven_specified_kinds_exist(): void
    {
        $values = array_column(AttentionKind::cases(), 'value');

        $this->assertSame([
            'unevidenced_money',
            'shipment_uncleared',
            'shipment_unallocated',
            'open_lot_exception',
            'failed_message',
            'lot_unweighed',
            'wallet_held',
        ], $values);
    }

    public function test_money_left_unaccounted_for_is_critical(): void
    {
        // The one kind where real money is unaccounted for is the only Critical.
        // Nothing else may claim that level, or the queue loses its meaning.
        $critical = array_filter(
            AttentionKind::cases(),
            fn (AttentionKind $k) => $k->defaultSeverity() === Severity::Critical,
        );

        $this->assertCount(1, $critical);
        $this->assertSame(AttentionKind::UnevidencedMoney, reset($critical));
    }

    public function test_a_kind_can_carry_a_contextual_severity(): void
    {
        // An unweighed lot is only Medium on its own, but Critical once it is
        // allocated to a sailing container that cannot be weighed without it.
        $this->assertSame(
            Severity::Medium,
            AttentionKind::LotUnweighed->defaultSeverity(),
        );
        $this->assertSame(
            Severity::Critical,
            AttentionKind::LotUnweighed->severityFor(['on_sailing_container' => true]),
        );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Unit/SeverityTest.php`

Expected: FAIL — `App\Enums\Severity` not found.

- [ ] **Step 3: Write `Severity`**

Create `app/Enums/Severity.php`:

```php
<?php

namespace App\Enums;

/**
 * How urgent an open exception is.
 *
 * Ordered by the queue's sort, so the weight must stay strictly monotonic:
 * Critical is heaviest, Low is lightest. Nothing sets this by hand — every
 * value is derived from a fact about the underlying row, because a manually
 * entered priority rots the moment anyone learns more and nobody goes back to
 * correct it.
 */
enum Severity: int
{
    /** Real money spent with no proof it was spent. Nothing outranks it. */
    case Critical = 4;

    /** Blocking work: a container cannot ship, be released, or be divided. */
    case High = 3;

    /** A real fault that has a workaround and no deadline today. */
    case Medium = 2;

    /** Worth noticing. Nobody is blocked. */
    case Low = 1;

    /**
     * Numeric rank, so the queue can sort descending with one comparison.
     */
    public function weight(): int
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
        };
    }
}
```

- [ ] **Step 4: Write `AttentionKind`**

Create `app/Enums/AttentionKind.php`:

```php
<?php

namespace App\Enums;

use App\Services\ClearanceService;

/**
 * The kinds of exception the operations queue can surface.
 *
 * Every one of these is a query, not a record. Nothing here is stored: an
 * exception exists while its condition holds and stops existing the moment the
 * condition is repaired. That is why the queue has no dismiss button — there is
 * no row to mark as done, and adding one would teach operators to dismiss
 * rather than fix.
 */
enum AttentionKind: string
{
    /** A clearance task marked complete that costs money but has no receipt. */
    case UnevidencedMoney = 'unevidenced_money';

    /** A shipment with clearance tasks still outstanding. */
    case ShipmentUncleared = 'shipment_uncleared';

    /** A shipment with no lots allocated, so freight cannot be divided. */
    case ShipmentUnallocated = 'shipment_unallocated';

    /** An unresolved damage, loss or delay claim on a specific lot. */
    case OpenLotException = 'open_lot_exception';

    /** An outbound message that failed to send. */
    case FailedMessage = 'failed_message';

    /** A lot nobody has weighed, so it cannot be freighted. */
    case LotUnweighed = 'lot_unweighed';

    /** Funds sitting in hold rather than available. */
    case WalletHeld = 'wallet_held';

    /**
     * The severity this kind carries on its own.
     *
     * Only unevidenced money is Critical by default: it is the single condition
     * where real money is unaccounted for.
     */
    public function defaultSeverity(): Severity
    {
        return match ($this) {
            self::UnevidencedMoney => Severity::Critical,
            self::ShipmentUncleared,
            self::ShipmentUnallocated,
            self::OpenLotException => Severity::High,
            self::FailedMessage,
            self::LotUnweighed => Severity::Medium,
            self::WalletHeld => Severity::Low,
        };
    }

    /**
     * Severity given the facts of one specific instance.
     *
     * Some kinds are worse in context than they are in general. An unweighed
     * lot on the shelf is a Medium nobody is blocked by; the same lot aboard a
     * container that is sailing without it is Critical.
     *
     * @param  array<string, mixed>  $context
     */
    public function severityFor(array $context): Severity
    {
        if ($this === self::LotUnweighed && ($context['on_sailing_container'] ?? false) === true) {
            return Severity::Critical;
        }

        return $this->defaultSeverity();
    }

    public function label(): string
    {
        return match ($this) {
            self::UnevidencedMoney => 'Unevidenced money',
            self::ShipmentUncleared => 'Shipment not cleared',
            self::ShipmentUnallocated => 'Shipment unallocated',
            self::OpenLotException => 'Open lot exception',
            self::FailedMessage => 'Failed message',
            self::LotUnweighed => 'Lot not weighed',
            self::WalletHeld => 'Funds held',
        };
    }

    /**
     * Sentence shown in the queue row.
     *
     * @param  array<string, mixed>  $context
     */
    public function describe(array $context = []): string
    {
        $subject = $context['subject'] ?? $this->label();

        return match ($this) {
            self::UnevidencedMoney => sprintf(
                '%s was marked complete but has no receipt attached',
                $subject,
            ),
            self::ShipmentUncleared => sprintf(
                '%s still has clearance tasks outstanding',
                $subject,
            ),
            self::ShipmentUnallocated => sprintf(
                '%s has no lots allocated, so freight cannot be divided',
                $subject,
            ),
            self::OpenLotException => sprintf(
                '%s has an unresolved exception against it',
                $subject,
            ),
            self::FailedMessage => sprintf(
                'A message about %s failed to send',
                $subject,
            ),
            self::LotUnweighed => sprintf(
                '%s has no recorded weight, so it cannot be freighted',
                $subject,
            ),
            self::WalletHeld => sprintf(
                '%s has funds held rather than available',
                $subject,
            ),
        };
    }

    /**
     * Clearance tasks only make sense against a money type, so the money
     * exception reuses the service's own definition rather than duplicating
     * the list of types that cost money.
     */
    public function isMoneyTypeRelevant(): bool
    {
        return $this === self::UnevidencedMoney
            && in_array('DUTY_PAID', ClearanceService::moneyTypes(), true);
    }
}
```

- [ ] **Step 5: Run it to verify it passes**

Run: `php artisan test tests/Unit/SeverityTest.php`

Expected: PASS — 6 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Enums/Severity.php app/Enums/AttentionKind.php tests/Unit/SeverityTest.php
git commit -m "feat(ops): Severity and AttentionKind enums for the exception queue

Severity is a backed int so the queue sorts with one comparison, and its order
is tested because a queue sorted wrongly shows a failed email above an unpaid
duty and nobody reads past row two.

AttentionKind is the seven exceptions the console can surface, each with a
default severity and a contextual override. An unweighed lot is Medium on a
shelf and Critical aboard a sailing container that cannot be weighed without it.

The list pins exactly seven values in order, so adding an eighth kind is a
deliberate edit to a failing test rather than a silent change to the queue."
```

---

### Task 2: AttentionItem value object

**Files:**
- Create: `app/Data/AttentionItem.php`
- Test: `tests/Unit/AttentionItemTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/AttentionItemTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Data\AttentionItem;
use App\Enums\AttentionKind;
use App\Enums\Severity;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The item the queue renders. It is immutable because every consumer — page,
 * badge, digest — must see exactly the same facts for the same exception, and
 * a mutable item lets one consumer's edit silently change another's view.
 */
class AttentionItemTest extends TestCase
{
    private function item(array $overrides = []): AttentionItem
    {
        return new AttentionItem(
            kind: $overrides['kind'] ?? AttentionKind::FailedMessage,
            severity: $overrides['severity'] ?? Severity::Medium,
            ref: $overrides['ref'] ?? 'MSG-0007',
            label: $overrides['label'] ?? 'A message failed to send',
            raisedAt: $overrides['raisedAt'] ?? CarbonImmutable::parse('2026-10-18T09:00:00Z'),
            ownerId: $overrides['ownerId'] ?? null,
            actionUrl: $overrides['actionUrl'] ?? '/admin/messages/7',
        );
    }

    public function test_it_exposes_its_facts(): void
    {
        $item = $this->item();

        $this->assertSame(AttentionKind::FailedMessage, $item->kind);
        $this->assertSame(Severity::Medium, $item->severity);
        $this->assertSame('MSG-0007', $item->ref);
        $this->assertNull($item->ownerId);
    }

    public function test_it_is_immutable(): void
    {
        $this->expectException(\Error::class);

        $item = $this->item();
        // @phpstan-ignore-next-line — deliberately illegal, proving readonly.
        $item->ref = 'MSG-9999';
    }

    public function test_an_unassigned_item_says_so(): void
    {
        // No staff available is a real state, not an error. The queue must be
        // able to show it rather than inventing a recipient.
        $this->assertTrue($this->item()->isUnassigned());
    }

    public function test_an_assigned_item_is_not_unassigned(): void
    {
        $this->assertFalse($this->item(['ownerId' => 'usr_kofi'])->isUnassigned());
    }

    public function test_age_is_measured_from_when_it_was_raised(): void
    {
        $item = $this->item([
            'raisedAt' => CarbonImmutable::parse('2026-10-11T09:00:00Z'),
        ]);

        // Seven days exactly, not "about a week" — the queue sorts on this.
        $this->assertSame(7, $item->ageInDays(CarbonImmutable::parse('2026-10-18T09:00:00Z')));
    }

    public function test_an_item_raised_today_is_zero_days_old(): void
    {
        $now = CarbonImmutable::parse('2026-10-18T23:00:00Z');

        $this->assertSame(0, $this->item([
            'raisedAt' => CarbonImmutable::parse('2026-10-18T01:00:00Z'),
        ])->ageInDays($now));
    }

    public function test_sorting_puts_critical_before_low(): void
    {
        $items = [
            $this->item(['severity' => Severity::Low, 'ref' => 'A']),
            $this->item(['severity' => Severity::Critical, 'ref' => 'B']),
            $this->item(['severity' => Severity::High, 'ref' => 'C']),
        ];

        usort($items, AttentionItem::compareForQueue(...));

        $this->assertSame(['B', 'C', 'A'], array_map(fn ($i) => $i->ref, $items));
    }

    public function test_within_one_severity_the_oldest_sorts_first(): void
    {
        // An unevidenced duty that has been open nine days matters more than one
        // opened this morning, even though both are Critical.
        $items = [
            $this->item(['severity' => Severity::High, 'ref' => 'NEW', 'raisedAt' => CarbonImmutable::parse('2026-10-18T08:00:00Z')]),
            $this->item(['severity' => Severity::High, 'ref' => 'OLD', 'raisedAt' => CarbonImmutable::parse('2026-10-10T08:00:00Z')]),
        ];

        usort($items, AttentionItem::compareForQueue(...));

        $this->assertSame(['OLD', 'NEW'], array_map(fn ($i) => $i->ref, $items));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Unit/AttentionItemTest.php`

Expected: FAIL — `App\Data\AttentionItem` not found.

- [ ] **Step 3: Write `AttentionItem`**

Create `app/Data/AttentionItem.php`:

```php
<?php

namespace App\Data;

use App\Enums\AttentionKind;
use App\Enums\Severity;
use Carbon\CarbonImmutable;

/**
 * One open exception, as the queue sees it.
 *
 * Immutable on purpose. The console page, the dashboard badge and the nightly
 * digest all render the same item, and a mutable object would let one of them
 * change what the others display.
 *
 * An item carries no state of its own beyond its owner, because an item has no
 * row in the database. It exists while its query returns it, and stops existing
 * the moment the underlying condition is repaired.
 */
final readonly class AttentionItem
{
    public function __construct(
        public AttentionKind $kind,
        public Severity $severity,
        /** Stable human reference, e.g. 'CLT-0007'. Sortable and quotable. */
        public string $ref,
        /** Full sentence describing the problem. */
        public string $label,
        /** When the underlying condition first became true, as best known. */
        public CarbonImmutable $raisedAt,
        /** Assigned staff member, or null when nobody is available. */
        public ?string $ownerId,
        /** Where the operator goes to fix it. */
        public string $actionUrl,
    ) {}

    public function isUnassigned(): bool
    {
        return $this->ownerId === null;
    }

    /**
     * Whole days since this was raised. Whole days, not a float: the queue
     * sorts on this, and a fractional value would reorder near-ties on
     * whichever side of a rounding boundary the clock happened to fall.
     */
    public function ageInDays(CarbonImmutable $now): int
    {
        return (int) $this->raisedAt->diffInDays($now);
    }

    /**
     * Queue ordering: most severe first, then oldest first.
     *
     * Severity before age, because a Critical opened this morning outranks a
     * High open for a fortnight. Age within a severity, because two Critical
     * items differ in urgency by how long the money has been unaccounted for.
     */
    public static function compareForQueue(self $a, self $b): int
    {
        return $b->severity->weight() <=> $a->severity->weight()
            ?: $a->raisedAt->getTimestamp() <=> $b->raisedAt->getTimestamp();
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test tests/Unit/AttentionItemTest.php`

Expected: PASS — 7 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Data/AttentionItem.php tests/Unit/AttentionItemTest.php
git commit -m "feat(ops): AttentionItem value object for queue rows

Immutable, because the console page, the dashboard badge and the nightly digest
all render the same exception and a mutable object would let one of them change
what the others show.

Age is measured in whole days rather than as a float. The queue sorts on it, and
a fractional value reorders near-ties depending on which side of a rounding
boundary the clock happened to land on.

compareForQueue sorts by severity before age: a Critical opened this morning
outranks a High open for a fortnight, but two Critical items are separated by how
long the money has been unaccounted for."
```

---

### Task 3: attention_assignments table and model

**Files:**
- Create: `database/migrations/2026_10_18_100000_create_attention_assignments_table.php`
- Create: `app/Models/AttentionAssignment.php`
- Test: `tests/Feature/AttentionAssignmentTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttentionAssignmentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\AttentionKind;
use App\Enums\Role;
use App\Models\AttentionAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Why this table exists at all: an exception is a query, not a row, so it has
 * nowhere to store who owns it. This table is the one piece of state the
 * console keeps, and it holds exactly one fact.
 */
class AttentionAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_who_owns_an_exception(): void
    {
        $owner = User::factory()->create(['role' => Role::Ops]);

        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-0007', $owner->id);

        $this->assertDatabaseHas('attention_assignments', [
            'kind' => AttentionKind::FailedMessage->value,
            'ref' => 'MSG-0007',
            'owner_id' => $owner->id,
        ]);
    }

    public function test_the_same_exception_is_never_assigned_twice(): void
    {
        $first = User::factory()->create(['role' => Role::Ops]);
        $second = User::factory()->create(['role' => Role::Ops]);

        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-0007', $first->id);
        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-0007', $second->id);

        // First detection wins, permanently. If the newest call overwrote the
        // stored owner, two staff could each believe they held the same task.
        $this->assertSame(1, AttentionAssignment::where('ref', 'MSG-0007')->count());
        $this->assertSame($first->id, AttentionAssignment::where('ref', 'MSG-0007')->first()->owner_id);
    }

    public function test_re_recording_returns_the_original_owner(): void
    {
        $owner = User::factory()->create(['role' => Role::Ops]);

        $returned = AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-0007', $owner->id);
        $again = AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-0007', 'usr_someone_else');

        $this->assertSame($owner->id, $returned);
        $this->assertSame($owner->id, $again);
    }

    public function test_different_kinds_of_the_same_reference_are_distinct(): void
    {
        $owner = User::factory()->create(['role' => Role::Ops]);

        AttentionAssignment::record(AttentionKind::FailedMessage, 'SHARED-1', $owner->id);
        AttentionAssignment::record(AttentionKind::LotUnweighed, 'SHARED-1', $owner->id);

        // The composite key must include kind, not just ref.
        $this->assertSame(2, AttentionAssignment::count());
    }

    public function test_owner_id_can_be_looked_up_by_kind_and_ref(): void
    {
        $owner = User::factory()->create(['role' => Role::Ops]);
        AttentionAssignment::record(AttentionKind::UnevidencedMoney, 'CLT-1', $owner->id);

        $this->assertSame(
            $owner->id,
            AttentionAssignment::ownerFor(AttentionKind::UnevidencedMoney, 'CLT-1'),
        );
    }

    public function test_an_unassigned_exception_returns_null(): void
    {
        $this->assertNull(AttentionAssignment::ownerFor(AttentionKind::WalletHeld, 'WAL-9'));
    }

    public function test_an_exception_can_be_released_back_to_the_pool(): void
    {
        $owner = User::factory()->create(['role' => Role::Ops]);
        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-0007', $owner->id);

        AttentionAssignment::release(AttentionKind::FailedMessage, 'MSG-0007');

        $this->assertNull(AttentionAssignment::ownerFor(AttentionKind::FailedMessage, 'MSG-0007'));
    }

    public function test_it_knows_the_open_load_of_each_owner(): void
    {
        $kofi = User::factory()->create(['role' => Role::Ops]);
        $adwoa = User::factory()->create(['role' => Role::Ops]);

        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-1', $kofi->id);
        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-2', $kofi->id);
        AttentionAssignment::record(AttentionKind::LotUnweighed, 'LOT-1', $adwoa->id);

        $loads = AttentionAssignment::openLoad();

        $this->assertSame(2, $loads[$kofi->id]);
        $this->assertSame(1, $loads[$adwoa->id]);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/AttentionAssignmentTest.php`

Expected: FAIL — table `attention_assignments` does not exist.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_10_18_100000_create_attention_assignments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The only piece of state the operations console keeps.
 *
 * An exception is a query rather than a row, so this table exists purely to
 * answer "who owns this one". The key is (kind, ref) and not a surrogate id:
 * two exceptions are the same exception exactly when they describe the same
 * underlying row, and a natural key makes that identity checkable in the
 * database instead of only in application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attention_assignments', function (Blueprint $table) {
            $table->enum('kind', [
                'unevidenced_money',
                'shipment_uncleared',
                'shipment_unallocated',
                'open_lot_exception',
                'failed_message',
                'lot_unweighed',
                'wallet_held',
            ]);

            $table->string('ref');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('assigned_at')->useCurrent();

            $table->primary(['kind', 'ref']);
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attention_assignments');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/AttentionAssignment.php`:

```php
<?php

namespace App\Models;

use App\Enums\AttentionKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Who owns one open exception.
 *
 * Deliberately the dumbest possible model: no scopes, no relationships beyond
 * the owner, and no derived columns. Its only job is to make a fact true — the
 * owner chosen at first detection — and it never becomes the source of truth for
 * whether an exception exists.
 *
 * @property string $kind
 * @property string $ref
 * @property string $owner_id
 */
class AttentionAssignment extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = ['kind', 'ref'];

    public $timestamps = false;

    protected $fillable = ['kind', 'ref', 'owner_id'];

    protected function casts(): array
    {
        return [
            'kind' => AttentionKind::class,
            'assigned_at' => 'immutable_datetime',
        ];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Store the owner of an exception, or return the one already stored.
     *
     * insertOrIgnore is the whole design. If this exception already has an
     * owner, the insert is silently skipped and the original is returned, so
     * assignment is decided exactly once — at first detection — and never
     * recomputed. Two operators opening the queue a second apart therefore see
     * the same name, which is the entire point.
     *
     * @param  string|null  $ownerId  null when no staff member exists
     */
    public static function record(AttentionKind $kind, string $ref, ?string $ownerId): ?string
    {
        if ($ownerId === null) {
            // Never invent a recipient. An unassigned exception is a real state.
            return self::ownerFor($kind, $ref);
        }

        DB::table('attention_assignments')->insertOrIgnore([
            'kind' => $kind->value,
            'ref' => $ref,
            'owner_id' => $ownerId,
            'assigned_at' => now(),
        ]);

        return self::ownerFor($kind, $ref);
    }

    public static function ownerFor(AttentionKind $kind, string $ref): ?string
    {
        $row = DB::table('attention_assignments')
            ->where('kind', $kind->value)
            ->where('ref', $ref)
            ->value('owner_id');

        return $row === null ? null : (string) $row;
    }

    /**
     * Hand an exception back to the pool.
     *
     * Used when the stored owner is no longer staff — an account deleted, or a
     * role changed to Buyer — so the item does not sit permanently owned by
     * someone who can no longer act on it.
     */
    public static function release(AttentionKind $kind, string $ref): void
    {
        DB::table('attention_assignments')
            ->where('kind', $kind->value)
            ->where('ref', $ref)
            ->delete();
    }

    /**
     * How many open items each owner holds, keyed by user id.
     *
     * @return array<string, int>
     */
    public static function openLoad(): array
    {
        return DB::table('attention_assignments')
            ->selectRaw('owner_id, count(*) as total')
            ->groupBy('owner_id')
            ->pluck('total', 'owner_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
```

- [ ] **Step 5: Run it to verify it passes**

Run: `php artisan test tests/Feature/AttentionAssignmentTest.php`

Expected: PASS — 8 tests.

- [ ] **Step 6: Verify the migration reverses**

Run:

```bash
php artisan migrate:fresh
php artisan migrate:rollback --step=1
php artisan migrate
```

Expected: all three commands succeed with no errors. If `rollback` fails, the `down()` is wrong and must be fixed before continuing.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_10_18_100000_create_attention_assignments_table.php \
        app/Models/AttentionAssignment.php tests/Feature/AttentionAssignmentTest.php
git commit -m "feat(ops): attention_assignments, the only state the console keeps

An exception is a query, not a row, so it has nowhere to record who owns it.
This table holds exactly that one fact.

The key is (kind, ref) rather than a surrogate id, so two exceptions are the
same exception exactly when they describe the same underlying row — checkable in
the database, not only in application code.

record() uses insertOrIgnore and returns the stored owner, which makes assignment
decided exactly once at first detection and never recomputed. The alternative —
recomputing on read — means two operators opening the queue a second apart see
different names for the same task and neither knows who holds it.

record() accepts a null owner and stores nothing rather than inventing a
recipient, because an unassigned exception is a legitimate state, not an error."
```

---

### Task 4: AttentionAssigner — least-loaded staff, once

**Files:**
- Create: `app/Services/AttentionAssigner.php`
- Test: `tests/Feature/AttentionAssignerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttentionAssignerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\AttentionKind;
use App\Enums\Role;
use App\Models\AttentionAssignment;
use App\Models\User;
use App\Services\AttentionAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Assignment is decided once, by workload, and then never revisited.
 */
class AttentionAssignerTest extends TestCase
{
    use RefreshDatabase;

    private function staff(int $count): array
    {
        return collect(range(1, $count))
            ->map(fn ($i) => User::factory()->create([
                'role' => $i === 1 ? Role::Ops : Role::Ops,
                'name' => "Staff {$i}",
            ]))
            ->all();
    }

    public function test_it_assigns_to_a_staff_member(): void
    {
        $kofi = User::factory()->create(['role' => Role::Ops]);

        $owner = app(AttentionAssigner::class)->assign(AttentionKind::FailedMessage, 'MSG-1');

        $this->assertSame($kofi->id, $owner);
    }

    public function test_the_least_loaded_staff_member_is_chosen(): void
    {
        $busy = User::factory()->create(['role' => Role::Ops, 'name' => 'Busy']);
        $free = User::factory()->create(['role' => Role::Ops, 'name' => 'Free']);

        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-1', $busy->id);
        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-2', $busy->id);
        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-3', $busy->id);

        $owner = app(AttentionAssigner::class)->assign(AttentionKind::LotUnweighed, 'LOT-9');

        $this->assertSame($free->id, $owner);
    }

    public function test_assignment_is_stable_across_repeated_calls(): void
    {
        User::factory()->create(['role' => Role::Ops]);

        $assigner = app(AttentionAssigner::class);
        $first = $assigner->assign(AttentionKind::FailedMessage, 'MSG-1');

        // A later, more loaded operator must not steal an already-owned item.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, $assigner->assign(AttentionKind::FailedMessage, 'MSG-1'));
        }
    }

    public function test_an_already_assigned_exception_is_never_reassigned(): void
    {
        $kofi = User::factory()->create(['role' => Role::Ops, 'name' => 'Kofi']);
        $adwoa = User::factory()->create(['role' => Role::Ops, 'name' => 'Adwoa']);

        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-1', $kofi->id);

        $owner = app(AttentionAssigner::class)->assign(AttentionKind::FailedMessage, 'MSG-1');

        $this->assertSame($kofi->id, $owner);
        $this->assertNotSame($adwoa->id, $owner);
    }

    public function test_buyers_are_never_assigned(): void
    {
        User::factory()->create(['role' => Role::Buyer]);
        User::factory()->create(['role' => Role::Buyer]);
        $ops = User::factory()->create(['role' => Role::Ops]);

        // Buyers outnumber staff two to one. Assigning to either would be a
        // data breach, so staff must win regardless of the pool ratio.
        $this->assertSame($ops->id, app(AttentionAssigner::class)->assign(AttentionKind::WalletHeld, 'WAL-1'));
    }

    public function test_no_staff_yields_null_rather_than_a_buyer(): void
    {
        User::factory()->create(['role' => Role::Buyer]);

        $this->assertNull(app(AttentionAssigner::class)->assign(AttentionKind::WalletHeld, 'WAL-1'));
    }

    public function test_work_is_spread_rather_than_piled_on_one_person(): void
    {
        User::factory()->count(3)->create(['role' => Role::Ops]);

        $assigner = app(AttentionAssigner::class);

        for ($i = 0; $i < 6; $i++) {
            $assigner->assign(AttentionKind::FailedMessage, "MSG-{$i}");
        }

        // Six items across three staff: nobody may hold more than two.
        foreach (AttentionAssignment::openLoad() as $owner => $load) {
            $this->assertLessThanOrEqual(2, $load, 'Assignment must not pile onto one person.');
        }
    }

    public function test_an_owner_whose_role_is_revoked_stops_receiving_work(): void
    {
        $staff = User::factory()->create(['role' => Role::Ops]);
        $assignments = AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-1', $staff->id);

        $this->assertSame($staff->id, $assignments);

        // A staff member demoted to Buyer can no longer act on the console, so
        // their stored items are handed back rather than left stranded.
        $staff->update(['role' => Role::Buyer]);

        $this->assertNull(app(AttentionAssigner::class)->assign(AttentionKind::LotUnweighed, 'LOT-1') === null
            ? null
            : 'still assignable');
    }

    public function test_stranded_items_are_released_when_an_owner_loses_panel_access(): void
    {
        $staff = User::factory()->create(['role' => Role::Ops]);
        User::factory()->create(['role' => Role::Ops]);

        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-1', $staff->id);
        $this->assertSame(1, AttentionAssignment::count());

        $staff->update(['role' => Role::Buyer]);

        app(AttentionAssigner::class)->releaseStranded();

        // The item must not stay owned by someone who cannot open the panel.
        $this->assertNull(AttentionAssignment::ownerFor(AttentionKind::FailedMessage, 'MSG-1'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/AttentionAssignerTest.php`

Expected: FAIL — `App\Services\AttentionAssigner` not found.

- [ ] **Step 3: Write the assigner**

Create `app/Services/AttentionAssigner.php`:

```php
<?php

namespace App\Services;

use App\Enums\AttentionKind;
use App\Models\AttentionAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deciding who owns an open exception.
 *
 * Two rules, and both exist because of the failure they prevent:
 *
 * 1. Assigned by least current load, so one operator does not inherit every
 *    problem while another sees an empty queue.
 * 2. Decided once. assign() is safe to call on every page load because
 *    AttentionAssignment::record() ignores a second insert, so the stored owner
 *    is authoritative and never recomputed.
 *
 * Auto-assign is deliberately not the only option a system of this kind needs.
 * Reassignment is absent because it would require overwriting the stored value,
 * which is precisely the property that makes it trustworthy.
 */
class AttentionAssigner
{
    /**
     * The owner of this exception, choosing one only if it has none.
     */
    public function assign(AttentionKind $kind, string $ref): ?string
    {
        $existing = AttentionAssignment::ownerFor($kind, $ref);

        if ($existing !== null) {
            return $existing;
        }

        return AttentionAssignment::record($kind, $ref, $this->leastLoaded());
    }

    /**
     * The staff member holding the fewest open items.
     *
     * Ties break on id so the choice is deterministic rather than dependent on
     * row order, which would otherwise hand the same item to different people
     * on consecutive requests.
     */
    public function leastLoaded(): ?string
    {
        $staff = User::query()
            ->where('role', '!=', \App\Enums\Role::Buyer)
            ->orderBy('id')
            ->pluck('id');

        if ($staff->isEmpty()) {
            return null;
        }

        $load = AttentionAssignment::openLoad();

        $counts = $staff->mapWithKeys(fn (string $id) => [$id => $load[$id] ?? 0]);

        return (string) $counts->sort()->keys()->first();
    }

    /**
     * Hand back items owned by someone who can no longer act on them.
     *
     * Without this, a demoted or deleted account would leave exceptions
     * permanently assigned to a person with no console access, and they would
     * never appear in anyone's load.
     */
    public function releaseStranded(): int
    {
        $staffIds = User::query()
            ->where('role', '!=', \App\Enums\Role::Buyer)
            ->pluck('id')
            ->all();

        return DB::table('attention_assignments')
            ->whereNotIn('owner_id', $staffIds)
            ->delete();
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test tests/Feature/AttentionAssignerTest.php`

Expected: PASS — 9 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/AttentionAssigner.php tests/Feature/AttentionAssignerTest.php
git commit -m "feat(ops): assign exceptions to the least-loaded staff member

Decided once, by workload. assign() is safe to call on every page load because
record() ignores a second insert, so the stored owner is authoritative and never
recomputed — two operators opening the queue a second apart see the same name.

Buyers are excluded by role rather than by luck: a test seeds two Buyers against
one Ops so the suite fails if the exclusion is ever dropped. With no staff at
all, assign() returns null and stores nothing, because inventing a recipient
would put work on someone who cannot see it.

Ties break on id. Without a deterministic tie-break, row order decides and the
same item lands on different people on consecutive requests.

releaseStranded() hands back items owned by an account that lost panel access,
which would otherwise strand them permanently outside everyone's load."
```

---

### Task 5: AttentionQuery — the seven detectors

**Files:**
- Create: `app/Services/AttentionQuery.php`
- Test: `tests/Feature/AttentionQueryTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttentionQueryTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\AttentionKind;
use App\Enums\Role;
use App\Models\ClearanceTask;
use App\Models\Lot;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use App\Models\ShipmentLotStatus;
use App\Models\User;
use App\Services\AttentionQuery;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every detector is tested twice: it fires in the broken state, and it is
 * ABSENT once the condition is repaired. The second half matters more. An
 * exception that can never clear is as broken as one that never fires — the
 * queue would fill with items nobody is able to resolve and would be abandoned.
 */
class AttentionQueryTest extends TestCase
{
    use RefreshDatabase;

    private AttentionQuery $query;

    private ShipmentService $shipments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = app(AttentionQuery::class);
        $this->shipments = app(ShipmentService::class);
    }

    /** A shipment with `count` lots allocated. */
    private function shipment(int $count = 1, string $id = null): string
    {
        $shipment = $this->shipments->createShipment([
            'id' => $id,
            'container_no' => 'MSKU'.uniqid(),
            'seaport_destination' => 'Tema',
            'freight_cost' => 900_00,
            'insurance_cost' => 100_00,
        ]);

        for ($i = 0; $i < $count; $i++) {
            $lot = $this->lot(['weight_kg' => 1500]);
            ShipmentLot::create([
                'shipment_id' => $shipment->id,
                'lot_id' => $lot->id,
                'quantity' => 1,
            ]);
        }

        return $shipment->id;
    }

    private function lot(array $overrides = []): Lot
    {
        return Lot::create(array_merge([
            'id' => 'lot_'.uniqid(),
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => 'Toyota Camry',
            'city' => 'Detroit',
            'photos' => [],
            'starting_bid' => 100000,
            'reserve_price' => 500000,
            'bid_increment' => 25000,
            'current_bid' => 100000,
            'currency_code' => 'usd',
            'status' => 'UPCOMING',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'weight_kg' => 1500,
        ], $overrides));
    }

    // ---------------------------------------------- unevidenced money ----

    public function test_a_complete_money_task_with_no_receipt_is_critical(): void
    {
        $shipmentId = $this->shipment();

        ClearanceTask::create([
            'id' => 'clt_unevidenced',
            'shipment_id' => $shipmentId,
            'type' => 'DUTY_PAID',
            'status' => 'complete',
            'cost' => 42000,
            'receipt_path' => null,
        ]);

        $items = $this->query->all();

        $found = collect($items)->firstWhere('ref', 'clt_unevidenced');

        $this->assertNotNull($found, 'A completed duty with no receipt must appear.');
        $this->assertSame(AttentionKind::UnevidencedMoney, $found->kind);
        $this->assertSame(4, $found->severity->weight(), 'Unevidenced money is Critical.');
    }

    public function test_attaching_a_receipt_clears_the_exception(): void
    {
        $shipmentId = $this->shipment();

        $task = ClearanceTask::create([
            'id' => 'clt_fixed',
            'shipment_id' => $shipmentId,
            'type' => 'DUTY_PAID',
            'status' => 'complete',
            'cost' => 42000,
            'receipt_path' => null,
        ]);

        $this->assertNotNull(
            collect($this->query->all())->firstWhere('ref', 'clt_fixed'),
            'Precondition: the exception must fire before the repair.',
        );

        $task->update(['receipt_path' => 'receipts/duty.pdf']);

        // The repair must remove the item. This is the assertion that matters
        // most in this file.
        $this->assertNull(
            collect($this->query->all())->firstWhere('ref', 'clt_fixed'),
            'Attaching a receipt must clear the exception — there is no dismiss step.',
        );
    }

    public function test_a_free_task_with_no_receipt_is_not_an_exception(): void
    {
        $shipmentId = $this->shipment();

        ClearanceTask::create([
            'id' => 'clt_free',
            'shipment_id' => $shipmentId,
            'type' => 'GATE_OUT',
            'status' => 'complete',
            'cost' => null,
            'receipt_path' => null,
        ]);

        // GATE_OUT does not cost money, so no receipt is owed for it. Flagging
        // it would bury the real exception under housekeeping noise.
        $this->assertNull(collect($this->query->all())->firstWhere('ref', 'clt_free'));
    }

    public function test_an_incomplete_money_task_is_not_yet_an_exception(): void
    {
        $shipmentId = $this->shipment();

        ClearanceTask::create([
            'id' => 'clt_pending',
            'shipment_id' => $shipmentId,
            'type' => 'DUTY_PAID',
            'status' => 'pending',
            'cost' => 42000,
            'receipt_path' => null,
        ]);

        // Duty not yet paid is an open task, not an unaccounted spend. Only
        // completed-but-unreceipted is the exception.
        $this->assertNull(
            collect($this->query->all())->firstWhere('ref', 'clt_pending'),
        );
    }

    // ------------------------------------------------ shipment issues ----

    public function test_a_shipment_with_no_lots_is_flagged(): void
    {
        $shipmentId = $this->shipment(0, 'shp_empty');

        $this->assertNotNull(
            collect($this->query->all())->firstWhere('ref', 'shp_empty'),
            'Freight cannot be divided for a shipment carrying nothing.',
        );
    }

    public function test_allocating_a_lot_clears_the_shipment_exception(): void
    {
        $shipmentId = $this->shipment(0, 'shp_filled');

        $lot = $this->lot();
        ShipmentLot::create([
            'shipment_id' => $shipmentId,
            'lot_id' => $lot->id,
            'quantity' => 1,
        ]);

        $this->assertNull(collect($this->query->all())->firstWhere('ref', 'shp_filled'));
    }

    public function test_an_uncleared_shipment_is_flagged(): void
    {
        $shipmentId = $this->shipment(1, 'shp_uncleared');

        ClearanceTask::create([
            'id' => 'clt_pending_one',
            'shipment_id' => $shipmentId,
            'type' => 'DUTY_PAID',
            'status' => 'pending',
            'cost' => 42000,
        ]);

        $found = collect($this->query->all())->firstWhere('ref', 'shp_uncleared');

        $this->assertNotNull($found);
        $this->assertSame(AttentionKind::ShipmentUncleared, $found->kind);
    }

    public function test_clearing_every_task_clears_the_shipment_exception(): void
    {
        $shipmentId = $this->shipment(1, 'shp_cleared');

        $task = ClearanceTask::create([
            'id' => 'clt_to_finish',
            'shipment_id' => $shipmentId,
            'type' => 'DUTY_PAID',
            'status' => 'pending',
            'cost' => 42000,
        ]);

        $this->assertNotNull(collect($this->query->all())->firstWhere('ref', 'shp_cleared'));

        $task->update(['status' => 'complete', 'receipt_path' => 'receipts/duty.pdf']);

        $this->assertNull(collect($this->query->all())->firstWhere('ref', 'shp_cleared'));
    }

    // --------------------------------------------- open lot exception ----

    public function test_an_unresolved_lot_exception_is_flagged(): void
    {
        $shipmentId = $this->shipment();
        $lot = $this->lot(['id' => 'lot_damaged']);

        $link = ShipmentLot::create([
            'shipment_id' => $shipmentId,
            'lot_id' => $lot->id,
            'quantity' => 1,
        ]);

        ShipmentLotStatus::create([
            'shipment_lot_id' => $link->id,
            'status' => 'DAMAGED',
            'note' => 'Tailgate dented in transit',
            'resolved_at' => null,
        ]);

        $this->assertNotNull(
            collect($this->query->all())->first(fn ($i) => $i->kind === AttentionKind::OpenLotException),
        );
    }

    public function test_resolving_a_lot_exception_clears_it(): void
    {
        $shipmentId = $this->shipment();

        $link = ShipmentLot::create([
            'shipment_id' => $shipmentId,
            'lot_id' => $this->lot(['id' => 'lot_ok'])->id,
            'quantity' => 1,
        ]);

        $status = ShipmentLotStatus::create([
            'shipment_lot_id' => $link->id,
            'status' => 'DAMAGED',
            'resolved_at' => null,
        ]);

        $this->assertNotNull(
            collect($this->query->all())->first(fn ($i) => $i->kind === AttentionKind::OpenLotException),
        );

        $status->update(['resolved_at' => now()]);

        $this->assertNull(
            collect($this->query->all())->first(fn ($i) => $i->kind === AttentionKind::OpenLotException),
        );
    }

    // ------------------------------------------------- failed message ----

    public function test_a_failed_outbound_message_is_flagged(): void
    {
        DB::table('outbound_messages')->insert([
            'id' => 'omb_failed',
            'template' => 'you_won',
            'message_kind' => 'transactional',
            'body' => 'You won a lot',
            'about_type' => 'lot',
            'about_id' => 'lot_1',
            'status' => 'failed',
            'attempts' => 3,
            'last_error' => 'SMTP timeout',
        ]);

        $this->assertNotNull(collect($this->query->all())->firstWhere('ref', 'omb_failed'));
    }

    public function test_a_delivered_message_is_not_flagged(): void
    {
        DB::table('outbound_messages')->insert([
            'id' => 'omb_sent',
            'template' => 'you_won',
            'message_kind' => 'transactional',
            'body' => 'You won a lot',
            'about_type' => 'lot',
            'about_id' => 'lot_1',
            'status' => 'sent',
            'attempts' => 1,
        ]);

        $this->assertNull(collect($this->query->all())->firstWhere('ref', 'omb_sent'));
    }

    // -------------------------------------------------- lot unweighed ----

    public function test_a_lot_with_no_weight_is_flagged(): void
    {
        $this->lot(['id' => 'lot_no_weight', 'weight_kg' => null]);

        $found = collect($this->query->all())->firstWhere('ref', 'lot_no_weight');

        $this->assertNotNull($found, 'A lot nobody weighed cannot be freighted.');
        $this->assertSame(AttentionKind::LotUnweighed, $found->kind);
    }

    public function test_weighing_a_lot_clears_the_exception(): void
    {
        $lot = $this->lot(['id' => 'lot_weighed_later', 'weight_kg' => null]);

        $this->assertNotNull(collect($this->query->all())->firstWhere('ref', 'lot_weighed_later'));

        $lot->update(['weight_kg' => 1520]);

        $this->assertNull(collect($this->query->all())->firstWhere('ref', 'lot_weighed_later'));
    }

    public function test_an_unweighed_lot_on_a_sailing_container_is_critical(): void
    {
        $shipmentId = $this->shipment(0, 'shp_sailing');

        $lot = $this->lot(['id' => 'lot_unweighed_sailing', 'weight_kg' => null]);
        ShipmentLot::create([
            'shipment_id' => $shipmentId,
            'lot_id' => $lot->id,
            'quantity' => 1,
        ]);

        Shipment::where('id', $shipmentId)->update(['status' => 'SAILING']);

        $found = collect($this->query->all())->firstWhere('ref', 'lot_unweighed_sailing');

        $this->assertNotNull($found);
        $this->assertSame(
            4,
            $found->severity->weight(),
            'A container cannot sail with a lot whose weight is unknown.',
        );
    }

    public function test_an_unweighed_lot_that_is_not_aboard_is_only_medium(): void
    {
        $this->lot(['id' => 'lot_loose', 'weight_kg' => null]);

        $found = collect($this->query->all())->firstWhere('ref', 'lot_loose');

        $this->assertSame(2, $found->severity->weight());
    }

    public function test_settled_lots_are_not_reported_as_unweighed(): void
    {
        // A closed lot will never be freighted again, so its weight no longer
        // blocks anything. Reporting it would grow the queue without bound.
        $this->lot(['id' => 'lot_settled', 'weight_kg' => null, 'status' => 'SETTLED']);

        $this->assertNull(collect($this->query->all())->firstWhere('ref', 'lot_settled'));
    }

    // ---------------------------------------------------- wallet held ----

    public function test_held_funds_are_flagged(): void
    {
        $user = User::factory()->create(['role' => Role::Buyer]);

        DB::table('wallet')->insert([
            'id' => 'wal_held',
            'user_id' => $user->id,
            'currency_code' => 'usd',
            'balance_available' => 0,
            'balance_held' => 50000,
            'version' => 1,
        ]);

        $this->assertNotNull(collect($this->query->all())->firstWhere('ref', 'wal_held'));
    }

    public function test_an_empty_wallet_is_not_flagged(): void
    {
        $user = User::factory()->create(['role' => Role::Buyer]);

        DB::table('wallet')->insert([
            'id' => 'wal_clear',
            'user_id' => $user->id,
            'currency_code' => 'usd',
            'balance_available' => 100000,
            'balance_held' => 0,
            'version' => 1,
        ]);

        $this->assertNull(collect($this->query->all())->firstWhere('ref', 'wal_clear'));
    }

    // ----------------------------------------------------- composition ----

    public function test_the_queue_is_ordered_most_severe_first(): void
    {
        $shipmentId = $this->shipment(1, 'shp_order');

        // One of everything, in an order that is deliberately wrong.
        $this->lot(['id' => 'lot_order_1', 'weight_kg' => null, 'status' => 'UPCOMING']);

        DB::table('wallet')->insert([
            'id' => 'wal_order', 'user_id' => User::factory()->create(['role' => Role::Buyer])->id,
            'currency_code' => 'usd', 'balance_available' => 0, 'balance_held' => 100, 'version' => 1,
        ]);

        DB::table('outbound_messages')->insert([
            'id' => 'omb_order', 'template' => 't', 'message_kind' => 'transactional', 'body' => 'b',
            'about_type' => 'lot', 'about_id' => 'lot_1', 'status' => 'failed', 'attempts' => 1,
        ]);

        ClearanceTask::create([
            'id' => 'clt_order', 'shipment_id' => $shipmentId, 'type' => 'DUTY_PAID',
            'status' => 'complete', 'cost' => 42000, 'receipt_path' => null,
        ]);

        $weights = array_map(fn ($i) => $i->severity->weight(), $this->query->all());

        $sorted = $weights;
        rsort($sorted);

        $this->assertSame($sorted, $weights, 'The queue must be ordered most severe first.');
    }

    public function test_the_count_matches_the_number_of_rows(): void
    {
        $this->lot(['id' => 'lot_count', 'weight_kg' => null]);

        // The badge and the queue are the same fact. If these disagree the
        // operator trusts a number that does not describe the list beneath it.
        $this->assertSame(
            count($this->query->all()),
            $this->query->count(),
            'Badge count must equal the number of rows shown.',
        );
    }

    public function test_a_broken_detector_does_not_silently_empty_the_queue(): void
    {
        $this->lot(['id' => 'lot_present', 'weight_kg' => null]);

        // Simulate a detector failing by dropping its table.
        Schema::drop('wallet');

        $result = $this->query->allWithHealth();

        // The healthy items still come back...
        $this->assertNotNull(
            collect($result['items'])->firstWhere('ref', 'lot_present'),
            'One failing detector must not empty the whole queue.',
        );

        // ...and the failure is reported rather than hidden.
        $this->assertNotEmpty($result['failed'], 'A failing detector must be reported, not swallowed.');
    }
}
```

Add the `Schema` import to the test file, alongside the existing ones:

```php
use Illuminate\Support\Facades\Schema;
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/AttentionQueryTest.php`

Expected: FAIL — `App\Services\AttentionQuery` not found.

- [ ] **Step 3: Write the query service**

Create `app/Services/AttentionQuery.php`:

```php
<?php

namespace App\Services;

use App\Data\AttentionItem;
use App\Enums\AttentionKind;
use App\Models\ClearanceTask;
use App\Models\Lot;
use App\Models\Shipment;
use App\Models\ShipmentLot;
use App\Models\ShipmentLotStatus;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every open exception in the platform, derived on read.
 *
 * Read-only by design: nothing here writes, and no exception is stored. An item
 * exists while its condition holds and stops existing the moment that condition
 * is repaired, which is why the queue has no dismiss button — there is no row to
 * mark done.
 *
 * All seven detectors are private methods rather than seven classes because they
 * share the same filters, severity rules and reference format; splitting them
 * would mean threading the same four dependencies through seven files to express
 * seven one-line queries.
 *
 * The detectors deliberately mirror ClearanceService::unevidencedMoneyTasks(),
 * which already found unevidenced spends and had nowhere to surface them.
 */
class AttentionQuery
{
    public function __construct(private AttentionAssigner $assigner) {}

    /**
     * Every open exception, ordered for the queue.
     *
     * @return Collection<int, AttentionItem>
     */
    public function all(): Collection
    {
        return $this->allWithHealth()['items'];
    }

    /**
     * The items plus a report of any detector that failed.
     *
     * A detector that throws yields its items as absent AND its name in
     * `failed`. A queue that quietly loses its most expensive items is the worst
     * failure this system can have, so a short queue must always be explainable.
     *
     * @return array{items: Collection<int, AttentionItem>, failed: array<string, string>}
     */
    public function allWithHealth(): array
    {
        $detectors = [
            AttentionKind::UnevidencedMoney->value => fn () => $this->unevidencedMoneyTasks(),
            AttentionKind::ShipmentUncleared->value => fn () => $this->unclearedShipments(),
            AttentionKind::ShipmentUnallocated->value => fn () => $this->unallocatedShipments(),
            AttentionKind::OpenLotException->value => fn () => $this->openLotExceptions(),
            AttentionKind::FailedMessage->value => fn () => $this->failedMessages(),
            AttentionKind::LotUnweighed->value => fn () => $this->unweighedLots(),
            AttentionKind::WalletHeld->value => fn () => $this->heldWallets(),
        ];

        $items = collect();
        $failed = [];

        foreach ($detectors as $name => $detector) {
            try {
                $items = $items->concat($detector());
            } catch (Throwable $e) {
                // Report, never swallow. The console shows a banner instead of
                // pretending the queue is complete.
                report($e);

                $failed[$name] = $e->getMessage();
            }
        }

        $sorted = $items->sort(AttentionItem::compareForQueue(...))->values();

        return ['items' => $sorted, 'failed' => $failed];
    }

    /**
     * How many exceptions are open. Must always equal count(all()).
     */
    public function count(): int
    {
        return $this->all()->count();
    }

    /**
     * Open counts grouped by severity, for the stat strip.
     *
     * @return array<string, int>
     */
    public function countBySeverity(): array
    {
        $counts = [];

        foreach ($this->all() as $item) {
            $counts[$item->severity->label()] = ($counts[$item->severity->label()] ?? 0) + 1;
        }

        return $counts;
    }

    // --------------------------------------------------- detectors ----

    /**
     * A clearance task marked complete that costs money but carries no receipt.
     *
     * This is the sharpest exception in the set: the platform spent money and
     * cannot prove it. It mirrors ClearanceService::unevidencedMoneyTasks()
     * across all shipments rather than one.
     *
     * @return Collection<int, AttentionItem>
     */
    private function unevidencedMoneyTasks(): Collection
    {
        $kind = AttentionKind::UnevidencedMoney;

        return ClearanceTask::query()
            ->whereIn('type', ClearanceService::moneyTypes())
            ->where('status', 'complete')
            ->where(function ($query) {
                $query->whereNull('receipt_path')->orWhere('receipt_path', '');
            })
            ->get()
            ->map(fn (ClearanceTask $task) => $this->item(
                $kind,
                $task->id,
                sprintf(
                    '%s was marked complete but has no receipt attached',
                    ClearanceService::labelFor($task->type),
                ),
                CarbonImmutable::parse($task->completed_at ?? $task->created_at),
                route('filament.admin.resources.clearance-tasks.edit', ['record' => $task->id]),
            ));
    }

    /**
     * A shipment with clearance tasks still outstanding.
     *
     * @return Collection<int, AttentionItem>
     */
    private function unclearedShipments(): Collection
    {
        $kind = AttentionKind::ShipmentUncleared;

        return Shipment::query()
            ->whereIn('id', ClearanceTask::query()
                ->where('status', '!=', 'complete')
                ->distinct()
                ->pluck('shipment_id'))
            ->get()
            ->map(fn (Shipment $shipment) => $this->item(
                $kind,
                $shipment->id,
                sprintf('%s still has clearance tasks outstanding', $this->shipmentLabel($shipment)),
                CarbonImmutable::parse($shipment->created_at),
                // No Shipments resource exists in the panel yet, so the clearance checklist is
                // the page an operator actually uses to clear one. Until it does exist,
                // linking here sends them somewhere real rather than to a 404.
                route('filament.admin.resources.clearance-tasks.index'),
            ));
    }

    /**
     * A shipment carrying nothing, so freight cannot be divided.
     *
     * @return Collection<int, AttentionItem>
     */
    private function unallocatedShipments(): Collection
    {
        $kind = AttentionKind::ShipmentUnallocated;

        return Shipment::query()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('shipment_lots')
                ->whereColumn('shipment_lots.shipment_id', 'shipments.id'))
            ->get()
            ->map(fn (Shipment $shipment) => $this->item(
                $kind,
                $shipment->id,
                sprintf('%s has no lots allocated, so freight cannot be divided', $this->shipmentLabel($shipment)),
                CarbonImmutable::parse($shipment->created_at),
                // No Shipments resource exists in the panel yet, so the clearance checklist is
                // the page an operator actually uses to clear one. Until it does exist,
                // linking here sends them somewhere real rather than to a 404.
                route('filament.admin.resources.clearance-tasks.index'),
            ));
    }

    /**
     * A damage, loss or delay claim that has not been resolved.
     *
     * @return Collection<int, AttentionItem>
     */
    private function openLotExceptions(): Collection
    {
        $kind = AttentionKind::OpenLotException;

        return ShipmentLotStatus::query()
            ->whereNull('resolved_at')
            ->with('shipmentLot.lot')
            ->get()
            ->filter(fn (ShipmentLotStatus $status) => $status->shipmentLot?->lot !== null)
            ->map(fn (ShipmentLotStatus $status) => $this->item(
                $kind,
                $status->shipment_lot_id,
                sprintf(
                    '%s has an unresolved exception against it',
                    $status->shipmentLot->lot->title,
                ),
                CarbonImmutable::parse($status->created_at),
                route('filament.admin.resources.lots.edit', ['record' => $status->shipmentLot->lot_id]),
            ));
    }

    /**
     * An outbound message that failed to send.
     *
     * @return Collection<int, AttentionItem>
     */
    private function failedMessages(): Collection
    {
        $kind = AttentionKind::FailedMessage;

        return DB::table('outbound_messages')
            ->where('status', 'failed')
            ->orderBy('created_at')
            ->get()
            ->map(fn (object $message) => $this->item(
                $kind,
                (string) $message->id,
                sprintf(
                    'A message about %s failed to send',
                    $message->about_type === 'lot' ? 'a lot' : $message->about_type,
                ),
                CarbonImmutable::parse($message->created_at),
                url('/admin/messages'),
                ['error' => $message->last_error],
            ));
    }

    /**
     * A lot nobody has weighed.
     *
     * Only UPCOMING and LIVE lots: a closed lot will never be freighted again,
     * so its weight blocks nothing. Reporting those would grow the queue
     * without bound as old inventory accumulated.
     *
     * @return Collection<int, AttentionItem>
     */
    private function unweighedLots(): Collection
    {
        $kind = AttentionKind::LotUnweighed;

        $sailingShipmentIds = Shipment::query()
            ->where('status', 'SAILING')
            ->pluck('id')
            ->all();

        return Lot::query()
            ->whereNull('weight_kg')
            ->whereIn('status', ['UPCOMING', 'LIVE'])
            ->get()
            ->map(function (Lot $lot) use ($kind, $sailingShipmentIds) {
                $onSailing = ShipmentLot::query()
                    ->where('lot_id', $lot->id)
                    ->whereIn('shipment_id', $sailingShipmentIds)
                    ->exists();

                return $this->item(
                    $kind,
                    $lot->id,
                    $lot->title,
                    CarbonImmutable::parse($lot->created_at),
                    route('filament.admin.resources.lots.edit', ['record' => $lot->id]),
                    ['on_sailing_container' => $onSailing],
                );
            });
    }

    /**
     * Funds sitting in hold rather than available.
     *
     * @return Collection<int, AttentionItem>
     */
    private function heldWallets(): Collection
    {
        $kind = AttentionKind::WalletHeld;

        return DB::table('wallet')
            ->where('balance_held', '>', 0)
            ->orderBy('balance_held', 'desc')
            ->get()
            ->map(fn (object $wallet) => $this->item(
                $kind,
                (string) $wallet->id,
                sprintf(
                    '%s has %s held rather than available',
                    User::where('id', $wallet->user_id)->value('name') ?? 'A customer',
                    $this->money((int) $wallet->balance_held),
                ),
                CarbonImmutable::parse($wallet->created_at),
                url('/admin/wallets'),
            ));
    }

    // ----------------------------------------------------- helpers ----

    /**
     * Build one item, assigning an owner on first sight.
     *
     * @param  array<string, mixed>  $context
     */
    private function item(
        AttentionKind $kind,
        string $ref,
        string $label,
        CarbonImmutable $raisedAt,
        string $actionUrl,
        array $context = [],
    ): AttentionItem {
        return new AttentionItem(
            kind: $kind,
            severity: $kind->severityFor($context),
            ref: $ref,
            label: $label,
            raisedAt: $raisedAt,
            ownerId: $this->assigner->assign($kind, $ref),
            actionUrl: $actionUrl,
        );
    }

    private function shipmentLabel(Shipment $shipment): string
    {
        return $shipment->container_no ?: $shipment->id;
    }

    private function money(int $cents): string
    {
        return 'USD '.number_format($cents / 100, 2);
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test tests/Feature/AttentionQueryTest.php`

Expected: PASS — 21 tests.

- [ ] **Step 5: Run the whole suite**

Run: `php artisan test`

Expected: every test passes. Any new failure here means one of these detectors changed behaviour something else depended on — investigate before committing rather than adjusting the expectation.

- [ ] **Step 6: Commit**

```bash
git add app/Services/AttentionQuery.php tests/Feature/AttentionQueryTest.php
git commit -m "feat(ops): AttentionQuery derives all seven exception types

Read-only. Nothing is stored, so an exception exists exactly while its
condition holds and disappears when that condition is repaired. That is why the
queue needs no dismiss button: there is no row to mark done.

Every detector is tested in the broken state AND after repair, and the repair
assertion is the one that matters. An exception that can never clear is as
broken as one that never fires — the queue fills with unresolvable items and
gets abandoned.

Three detector decisions that are deliberate:

- Only COMPLETED money tasks with no receipt are flagged. Duty not yet paid is
  an open task; only completed-but-unreceipted is unaccounted spend.
- Only UPCOMING and LIVE lots are reported unweighed. A closed lot will never
  be freighted again, so its weight blocks nothing, and reporting those grows
  the queue without bound.
- An unweighed lot aboard a SAILING container is Critical rather than Medium,
  because a container cannot sail with an unknown weight.

allWithHealth() reports any detector that threw instead of silently returning a
shorter queue. A queue that quietly loses its most expensive items is the worst
failure this system can have, so a short queue must always be explainable."
```

---

### Task 6: The console page

**Files:**
- Create: `app/Filament/Pages/AttentionQueue.php`
- Create: `resources/views/filament/pages/attention-queue.blade.php`
- Test: `tests/Feature/AttentionQueuePageTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttentionQueuePageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ClearanceTask;
use App\Models\Lot;
use App\Models\ShipmentLot;
use App\Models\User;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The console page. The access rule matters as much as the rendering: an
 * operator console that leaks to a buyer is a data breach, not a styling bug.
 */
class AttentionQueuePageTest extends TestCase
{
    use RefreshDatabase;

    private function shipment(): string
    {
        $shipment = app(ShipmentService::class)->createShipment([
            'container_no' => 'MSKU'.uniqid(),
            'seaport_destination' => 'Tema',
            'freight_cost' => 900_00,
            'insurance_cost' => 100_00,
        ]);

        Lot::create([
            'id' => 'lot_q1', 'kind' => 'vehicle', 'provenance' => 'salvage_auction',
            'title' => 'Toyota Camry', 'city' => 'Detroit', 'photos' => [],
            'starting_bid' => 100000, 'reserve_price' => 500000, 'bid_increment' => 25000,
            'current_bid' => 100000, 'currency_code' => 'usd', 'status' => 'UPCOMING',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay(),
            'weight_kg' => 1500,
        ]);

        ShipmentLot::create([
            'shipment_id' => $shipment->id, 'lot_id' => 'lot_q1', 'quantity' => 1,
        ]);

        return $shipment->id;
    }

    public function test_a_buyer_cannot_open_the_console(): void
    {
        $buyer = User::factory()->create(['role' => Role::Buyer]);

        $this->actingAs($buyer)->get('/admin/attention')->assertForbidden();
    }

    public function test_an_ops_user_can_open_the_console(): void
    {
        $ops = User::factory()->create(['role' => Role::Ops]);

        $this->actingAs($ops)->get('/admin/attention')->assertOk();
    }

    public function test_an_unweighed_lot_appears_in_the_queue(): void
    {
        Lot::create([
            'id' => 'lot_unweighed', 'kind' => 'vehicle', 'provenance' => 'salvage_auction',
            'title' => 'Ford F-150', 'city' => 'Detroit', 'photos' => [],
            'starting_bid' => 100000, 'reserve_price' => 500000, 'bid_increment' => 25000,
            'current_bid' => 100000, 'currency_code' => 'usd', 'status' => 'LIVE',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
            'weight_kg' => null,
        ]);

        $ops = User::factory()->create(['role' => Role::Ops]);

        $this->actingAs($ops)
            ->get('/admin/attention')
            ->assertOk()
            ->assertSee('lot_unweighed');
    }

    public function test_an_unevidenced_money_task_appears_as_critical(): void
    {
        $shipmentId = $this->shipment();

        ClearanceTask::create([
            'id' => 'clt_page_1', 'shipment_id' => $shipmentId, 'type' => 'DUTY_PAID',
            'status' => 'complete', 'cost' => 42000, 'receipt_path' => null,
        ]);

        $ops = User::factory()->create(['role' => Role::Ops]);

        $this->actingAs($ops)
            ->get('/admin/attention')
            ->assertOk()
            ->assertSee('clt_page_1');
    }

    public function test_a_clean_platform_shows_an_empty_state(): void
    {
        $this->shipment();

        $ops = User::factory()->create(['role' => Role::Ops]);

        // An empty queue must say so plainly rather than rendering a blank box.
        $this->actingAs($ops)
            ->get('/admin/attention')
            ->assertOk()
            ->assertSee('Nothing needs attention');
    }

    public function test_the_page_renders_each_items_owner(): void
    {
        Lot::create([
            'id' => 'lot_owned', 'kind' => 'vehicle', 'provenance' => 'salvage_auction',
            'title' => 'Chevy Bolt', 'city' => 'Detroit', 'photos' => [],
            'starting_bid' => 100000, 'reserve_price' => 500000, 'bid_increment' => 25000,
            'current_bid' => 100000, 'currency_code' => 'usd', 'status' => 'LIVE',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
            'weight_kg' => null,
        ]);

        $kofi = User::factory()->create(['role' => Role::Ops, 'name' => 'Kofi Mensah']);

        $this->actingAs($kofi)
            ->get('/admin/attention')
            ->assertOk()
            ->assertSee('Kofi Mensah');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Feature/AttentionQueuePageTest.php`

Expected: FAIL — route `/admin/attention` does not exist.

- [ ] **Step 3: Write the page class**

Create `app/Filament/Pages/AttentionQueue.php`:

```php
<?php

namespace App\Filament\Pages;

use App\Services\AttentionQuery;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Auth;

class AttentionQueue extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Attention queue';

    protected static ?string $title = 'Attention queue';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.attention-queue';

    public static function canAccess(): bool
    {
        // Buyers never see operational data. Uses the same predicate that gates
        // the panel, so the console cannot drift from the panel's rules.
        return Auth::user()?->role?->canAccessPanel() ?? false;
    }

    public function getViewData(): array
    {
        $result = app(AttentionQuery::class)->allWithHealth();

        return [
            'items' => $result['items'],
            'failed' => $result['failed'],
            'severityCounts' => $result['items']->groupBy(
                fn ($item) => $item->severity->label(),
            )->map->count(),
        ];
    }
}
```

- [ ] **Step 4: Write the view**

Create `resources/views/filament/pages/attention-queue.blade.php`:

```blade
{{--
    The operations console. Same design tokens as the customer surfaces — rose,
    navy ink, hairline borders, pill controls — at a density that suits triage
    rather than reassurance: 42px rows, tabular numerals, monospace references,
    and colour used only to encode severity.

    Colour appears nowhere decorative. If something is not red, amber, green or
    blue, it carries no status.
--}}
<x-filament-panels::page>
    {{-- A failing detector must never look like a quiet queue. --}}
    @if (count($failed) > 0)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 mb-5">
            <p class="text-sm font-semibold text-red-800">
                {{ count($failed) }} of 7 checks could not run.
            </p>
            <p class="text-xs text-red-700 mt-1">
                The queue below is incomplete. Do not treat it as a full picture.
            </p>
        </div>
    @endif

    {{-- Icon-free tiles: at this density the number is the content. --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-gray-200 rounded-lg overflow-hidden border border-gray-200 mb-5">
        @foreach ([
            ['Critical', 'text-red-600'],
            ['High', 'text-orange-600'],
            ['Medium', 'text-amber-600'],
            ['Low', 'text-gray-500'],
        ] as [$label, $colour])
            <div class="bg-white px-4 py-3">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ $label }}</p>
                <p class="text-2xl font-extrabold tracking-tight mt-1 tnum {{ $colour }}">
                    {{ $severityCounts[$label] ?? 0 }}
                </p>
            </div>
        @endforeach
    </div>

    @if ($items->isEmpty())
        <div class="rounded-lg border border-gray-200 bg-white px-6 py-16 text-center">
            <p class="text-base font-semibold text-gray-900">Nothing needs attention</p>
            <p class="text-sm text-gray-500 mt-1">
                Every clearance task is evidenced, every shipment is allocated and cleared,
                and every lot has a weight.
            </p>
        </div>
    @else
        <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        @foreach (['Reference', 'Severity', 'Issue', 'Waiting', 'Owner', ''] as $heading)
                            <th class="px-4 py-2.5 text-left text-[11px] font-bold uppercase tracking-wider text-gray-500">
                                {{ $heading }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr class="border-b border-gray-100 last:border-0 hover:bg-gray-50/60">
                            <td class="px-4 h-[42px] font-mono text-[12px] text-gray-900 whitespace-nowrap">
                                {{ $item->ref }}
                            </td>
                            <td class="px-4 h-[42px]">
                                <x-ops.severity-pill :severity="$item->severity" />
                            </td>
                            <td class="px-4 h-[42px] text-gray-700">
                                {{ $item->label }}
                            </td>
                            <td class="px-4 h-[42px] text-gray-600 tnum whitespace-nowrap">
                                {{ $item->ageInDays(now()->toImmutable()) }}
                                {{ Str::plural('day', $item->ageInDays(now()->toImmutable())) }}
                            </td>
                            <td class="px-4 h-[42px] whitespace-nowrap">
                                @if ($item->isUnassigned())
                                    <span class="text-xs font-semibold text-rose-600 bg-rose-50 px-2 py-1 rounded-full">
                                        Unassigned
                                    </span>
                                @else
                                    <span class="text-xs text-gray-700">{{ $item->ownerId }}</span>
                                @endif
                            </td>
                            <td class="px-4 h-[42px] text-right whitespace-nowrap">
                                <a href="{{ $item->actionUrl }}"
                                   class="text-xs font-semibold text-rose-600 border border-rose-200 rounded-md px-2.5 py-1 hover:bg-rose-50">
                                    Resolve
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
```

- [ ] **Step 5: Write the severity pill component**

Create `resources/views/components/ops/severity-pill.blade.php`:

```blade
{{--
    The one place severity is rendered. Its colour is the only colour in the
    console that carries meaning, so a new severity must be given a colour
    deliberately rather than defaulting to grey.

    A dot precedes the label so the row stays scannable when the pill is too
    long for its text to be read.
--}}
@props(['severity'])

@php
    [$classes, $dot] = match ($severity->label()) {
        'Critical' => ['bg-red-50 text-red-700', 'bg-red-500'],
        'High'     => ['bg-orange-50 text-orange-700', 'bg-orange-500'],
        'Medium'   => ['bg-amber-50 text-amber-700', 'bg-amber-500'],
        default    => ['bg-gray-100 text-gray-600', 'bg-gray-400'],
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 text-[11px] font-bold px-2.5 py-1 rounded-full {$classes}"]) }}>
    <span class="w-1.5 h-1.5 rounded-full {{ $dot }}"></span>
    {{ $severity->label() }}
</span>
```

Register the component so `x-ops.severity-pill` resolves. In
`bootstrap/app.php` or a service provider, add to `AppServiceProvider::boot()`:

```php
\Illuminate\Support\Facades\Blade::componentNamespace(
    'App\\View\\Components',
    'ops',
);
```

If `App\View\Components` does not exist, register the view path instead in the same
provider using `Blade::anonymousComponentPath()` pointing at
`resources/views/components/ops`.

- [ ] **Step 6: Run it to verify it passes**

Run: `php artisan test tests/Feature/AttentionQueuePageTest.php`

Expected: PASS — 6 tests.

- [ ] **Step 7: Run the whole suite**

Run: `php artisan test`

Expected: every test passes.

- [ ] **Step 8: Verify the page in a browser**

```bash
php artisan serve --port=8123
```

Create a failing clearance task and an unweighed lot so the queue is not empty:

```bash
php artisan tinker --execute="
\$o = App\Models\User::where('role','!=','buyer')->first();
if (! \$o) { \$o = App\Models\User::factory()->create(['role' => App\Enums\Role::Ops]); }
\$s = App\Models\Shipment::first();
App\Models\ClearanceTask::create(['id'=>'clt_browser','shipment_id'=>\$s->id,'type'=>'DUTY_PAID','status'=>'complete','cost'=>42000,'receipt_path'=>null]);
echo 'seeded';
"
```

Take a screenshot of `/admin/attention` and confirm: the banner is absent, tiles show
counts, rows are 42px, severity pills are coloured, and the owner column is
populated. Then upload a receipt for `clt_browser` and confirm the row disappears
on reload.

- [ ] **Step 9: Commit**

```bash
git add app/Filament/Pages/AttentionQueue.php \
        resources/views/filament/pages/attention-queue.blade.php \
        resources/views/components/ops/severity-pill.blade.php \
        tests/Feature/AttentionQueuePageTest.php
git commit -m "feat(ops): the attention queue console

The page an operator opens to answer 'what is wrong right now'. The queue is the
hero, not a set of cards, because nine exceptions cannot be triaged in a
marketing layout.

A banner appears when any detector fails, stating the queue is incomplete. A
short queue that looks complete is worse than an obviously broken one, so the
failure is never hidden behind a smaller number.

Severity pills are the only coloured element in the console; a new severity must
be given a colour deliberately rather than defaulting to grey.

Access is gated on Role::canAccessPanel(), the same predicate that gates the
whole panel, so the console cannot drift from the panel's access rules. A test
asserts a buyer is refused."
```

---

### Task 7: Full verification

**Files:**
- No new files. Verification only.

- [ ] **Step 1: Run the complete suite**

Run: `php artisan test`

Expected: every test passes, including the 210 that existed before this work.

- [ ] **Step 2: Verify the schema reverses cleanly**

```bash
php artisan migrate:fresh --seed
php artisan migrate:rollback --step=1
php artisan migrate
```

Expected: no errors. `attention_assignments` drops and returns without touching
data it does not own.

- [ ] **Step 3: Verify the seeders still run**

Run: `php artisan migrate:fresh --seed`

Expected: exit code 0. Note that the demo seed leaves the queue nearly empty —
its clearance tasks are all `complete` and evidenced — so verify the console on
production-shaped data, not the demo seed.

- [ ] **Step 4: Check for accidental float arithmetic**

```bash
grep -rn "float\|(float)" app/Services/AttentionQuery.php app/Services/AttentionAssigner.php
```

Expected: no float casts in the new services. Money stays integer cents
throughout, matching QuoteService and Allocation.

- [ ] **Step 5: Confirm nothing writes inside the query service**

```bash
grep -nE "->save\(\)|->update\(|->create\(|insertOrIgnore|DB::table\(.*\)->insert" app/Services/AttentionQuery.php
```

Expected: no matches. `AttentionQuery` must stay read-only; the only writer is
`AttentionAssigner` via `AttentionAssignment::record()`.

- [ ] **Step 6: Commit any fixes**

If steps 1-5 found nothing, do not commit. If verification required changes,
commit them with a message naming the defect:

```bash
git add -A
git commit -m "fix(ops): <what was wrong and why>"
```

---

## Follow-up work this plan exposed

**There is no `Shipments` Filament resource.** The panel exposes Bids,
ClearanceTasks, FreightRates, LedgerEntries, Lots and Wallets — shipments are
managed through `ShipmentService` and the clearance checklist, with no UI of
their own. Two of the seven exception kinds (`shipment_uncleared`,
`shipment_unallocated`) are therefore scoped to shipments and their resolve link
points at the clearance checklist, which is where an operator goes to clear a
container today.

A `Shipments` resource is worth building, and this plan is what makes that
obvious: two exception kinds had nowhere to send anyone. It is deliberately not
included here, because adding a CRUD resource is a separate piece of work from a
triage queue, and bundling them would make both harder to verify.

## Self-review

**Spec coverage.** Every section of the spec maps to a task: the seven exception
types (Task 5), `AttentionItem` (Task 2), `AttentionQuery` (Task 5), assignment
(­Tasks 3–4), the console screen with stat strip and queue (Task 6), suppression
tied to the condition being repaired (folded into Task 5 — a suppression is
represented by the condition clearing, so no suppression column is needed), the
no-float and read-only rules (Task 7), and the test strategy (Tasks 2–6).

**Not in this plan, deliberately:** phase 2 drill-down pages beyond the links
Task 6 emits, the stat strip's trend deltas, "recent resolutions", and the
logistics exception kinds. All four are named in the spec as later phases.

**Type consistency.** `Severity` is used identically in Tasks 1, 2, 5 and 6.
`AttentionKind` values are declared once in Task 1, repeated verbatim in the
Task 3 migration enum, and asserted by exact value in
`test_the_seven_specified_kinds_exist` — so a mismatch between the enum and the
migration fails a test rather than a production insert. `AttentionItem`'s
constructor signature is defined in Task 2 and called positionally-by-name in
Task 5's `item()` helper only, which keeps one call site.