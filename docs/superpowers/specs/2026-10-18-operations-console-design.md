# Operations Console — Design

**Date:** 2026-10-18
**Status:** Draft for review
**Branch:** `multi-kind-lots`

## Why this exists

Opoku's admin is a set of Filament resource CRUD screens. It can edit a clearance
task, but it cannot answer the only question an operator actually has at the start
of a shift: *what is wrong right now, and who is dealing with it?*

That question is not answerable from resource screens, because the exceptions that
matter are **derived from relationships between rows** rather than stored on any
one row. A clearance task with a cost but no receipt is a problem, but the task row
itself is perfectly valid. An unevidenced spend is an absence, and CRUD screens
only show presences.

The console exists to make those absences visible, ranked, and owned.

## Scope

**Phase 1 — this spec.** The attention queue and the seven exception types
derivable from the schema as it exists today. Useful on day one against real data,
and the spine every later ops screen links into.

**Phase 2 — later spec.** Drill-down screens behind each row: clearance task
detail, shipment timeline, message log, wallet.

**Phase 3 — later spec.** Logistics exceptions (failed pickup, weigh-in mismatch,
customs hold). These cannot ship before the logistics layer exists. Adding them is
additive: a new exception type in the same queue, not a redesign.

Explicitly **not** in scope: replacing Filament, redesigning the buyer storefront,
the logistics rate card (separately specified, parked), and any shipping of
`docs/previews/*.html` as application code.

## The exception set

Seven types, all derivable from tables that exist today. No invented data.

| Kind | Derived from | Severity | Why it matters |
|---|---|---|---|
| `unevidenced_money` | `clearance_tasks` where the type costs money and `receipt_path IS NULL` | Critical | The platform spent money with no proof it was spent |
| `shipment_uncleared` | a shipment with incomplete `clearance_tasks` | High | Container cannot be released |
| `shipment_unallocated` | `shipments` with no `shipment_lots` | High | Freight cannot be divided; already throws in `ClearanceService` |
| `open_lot_exception` | `shipment_lot_statuses.resolved_at IS NULL` | High | A damage, loss or delay claim on a specific lot is open |
| `failed_message` | `outbound_messages` with `status = failed` | Medium | A buyer was never told they won |
| `lot_unweighed` | `lot.weight_kg IS NULL` | Medium | The lot cannot be freighted; Task 1 deliberately stopped guessing weights |
| `wallet_held` | `wallet.balance_held > 0` | Low | Funds sitting in hold rather than available |

The unevidenced-money type is the sharpest of the seven, and it is worth naming why
it exists at all: `ClearanceService::unevidencedMoneyTasks()` was already written to
find these, and nothing ever surfaced its results. This spec gives that method a
place on screen.

## Architecture

### One query service, many surfaces

A single `AttentionQuery` service returns every open exception as one typed
collection of `AttentionItem`.

Two new types are introduced here, neither of which exists yet — the only enum in
the codebase today is `App\Enums\Role`:

- `App\Enums\Severity` — `Critical`, `High`, `Medium`, `Low`, ordered so the queue
  can sort by it.
- `App\Data\AttentionItem` — the readonly value object below.

```php
final class AttentionItem
{
    public function __construct(
        public readonly string $kind,        // 'unevidenced_money', ...
        public readonly Severity $severity,  // Critical | High | Medium | Low
        public readonly string $ref,         // 'CLT-0007', 'SHP-0042'
        public readonly string $label,       // human sentence
        public readonly CarbonImmutable $raisedAt,
        public readonly ?User $owner,
        public readonly string $actionUrl,
    ) {}
}
```

Everything reads from it: the console queue, a dashboard badge count, a nightly
email digest, a Slack webhook. Seven exception types must not become seven
scattered queries that drift apart — the badge on the dashboard and the row in the
queue are the same fact, and a queue whose count disagrees with its contents is
worse than no queue.

`AttentionQuery` is read-only. It derives; it never writes.

### Severity is derived, never entered

Nobody types "high priority" into a field. Severity is a function of the row:
a task with `cost > 0` and no receipt is Critical *because* money is unaccounted
for, not because someone triaged it.

The practical consequence is that severity cannot rot. A manually-set priority
becomes wrong the moment someone learns more, and nobody goes back to fix it.

### An item leaves the queue when its row changes

Every exception is a query, not a record. When the receipt is uploaded, the
`unevidenced_money` query simply stops returning that task. Nothing marks it done.

This is why there is **no dismiss button**. A dismissable queue is a queue that
learns to be ignored within a week, because dismissing is cheaper than fixing. If
the underlying condition still holds, the item comes back on the next load.

The one exception: a genuine false positive must be suppressible, or operators
will work around the queue. Suppression records the reason and expires.

### Ownership: assigned once, never recomputed

An exception is assigned to the least-loaded staff member **at the moment it is
first detected**, and the assignment is stored. Recomputing on every page load was
rejected: two staff opening the same queue seconds apart would see different owners
for the same task, and neither would know who actually held it.

The staff pool is the set of users whose `Role::canAccessPanel()` is true — `Ops`
and `Owner`, never `Buyer`. That predicate already gates Filament, so there is one
definition of "staff" and the queue cannot drift from the panel's access rules.

Workload is counted as open assigned items, so reassignment is not offered in
phase 1: it would require changing stored assignment, which is the property that
makes the stored value trustworthy.

## The console screen

One screen, following the dense operations layout in
`docs/previews/operations-console.html`. That preview is a design artefact; its
invented references (`SHP-0042`, `PC-11904`) are placeholders and must not survive
into the real screen, which reads only the seven kinds above.

Structure, top to bottom:

1. **Stat strip** — icon-free tiles: open by severity, oldest item, count resolved
   in 7 days. Deltas compare to the previous 7 days.
2. **The queue** — the hero. Filterable by kind, severity and owner. Sorted by
   severity, then oldest first. One action per row, linking to the phase 2
   drill-down.
3. **Clearance health** — one bar per shipment showing task completion, so a stuck
   container is visible without reading every row.
4. **Recent resolutions** — proof the queue empties, and the fastest way to catch
   a bad bulk action.

### Design tokens

The reference theme's language, adopted wholesale: rose `#E1537A`, navy ink
`#16193A`, body grey `#6E7280`, 14px card radius, hairline `#E8EAEF` borders, the
icon rail, pill controls, Poppins.

Layout follows the operations density, not the customer dashboard's cards: 42px
rows, tabular numerals, monospace references. In the console, colour is
**functional** — severity encoding — and nothing is coloured decoratively.

## Data flow

```
AttentionQuery  ──read──▶  clearance_tasks, shipment_lots, shipments,
                            shipment_lot_statuses, outbound_messages,
                            lot, wallet, users
       │
       ├─▶ console queue
       ├─▶ dashboard badge count
       └─▶ nightly digest

AssignmentWriter ──write──▶ attention_assignments (ref, kind, owner, assigned_at)
```

`attention_assignments` is the one new table. It exists solely to hold the owner
chosen at first detection, because the exception itself is derived and has nowhere
to store that fact.

Its primary key is `(kind, ref)` rather than a surrogate id. Two exceptions are the
same exception when they describe the same underlying row, and a natural key makes
that identity checkable in the database rather than only in application code.

## Error handling

- **A query that throws must not empty the queue.** A database error in one
  exception type yields that type as failed-and-hidden plus a console banner, never
  a silently shorter queue. A queue that quietly loses its most expensive items is
  the worst failure this system can have.
- **No staff means no assignment.** With an empty staff pool, items render
  `Unassigned` and are ordered first. Auto-assign must never invent a recipient.
- **Suppression requires a reason.** No free-text dismissal.

## Testing

The seven exceptions are integration-tested against a seeded database, asserting
each is detected in the broken state and **absent once the condition is repaired**.
The second half matters more: an exception that can never clear is as broken as one
that never fires.

- `AttentionQueryTest` — one test per exception type, each with a repair assertion.
- `AttentionAssignmentTest` — least-loaded selection, stored-once semantics, and
  the invariant that assignment is stable across repeated reads.
- `AttentionSeverityTest` — severity derived from row facts, including that a
  zero-cost task with no receipt is *not* critical.
- A queue-count-consistency test: the badge number equals the row count.

## Decisions taken

| Question | Decision | Why |
|---|---|---|
| Unassigned exceptions | Auto-assign by workload | Even load without a manager |
| Assignment lifetime | Stored once, never recomputed | Two staff must see the same owner |
| Dismiss button | None | Items leave when the condition clears |
| Severity entry | Derived only | Cannot rot or be argued with |
| Staff pool | `Role::canAccessPanel()` | One definition, shared with Filament |

## Concurrency

Two operators acting on the same exception simultaneously: **the second is blocked
and told who got there first**, with the timestamp. Last-write-wins was rejected
because these rows carry receipts and clearance decisions — an operator's attached
receipt silently overwritten by a colleague's stale form would leave the ledger
without evidence, which is precisely the failure the `unevidenced_money` exception
exists to surface.

The refusal is a normal, expected response, not an error. The queue shows
"already resolved by Kofi at 14:32" and the row leaves the queue.

## Phase 2 drill-down

Drill-down screens are **Filament resource pages**, and `actionUrl` points into
Filament. That reuses its auth, forms and table machinery rather than rebuilding
them for six screens. Its default density will not match the console and each
target needs custom styling to close that gap — accepted as the cheaper trade.

## Resolved decisions

| Question | Decision | Why |
|---|---|---|
| Unassigned exceptions | Auto-assign by workload | Even load without a manager |
| Assignment lifetime | Stored once, never recomputed | Two staff must see the same owner |
| Dismiss button | None | Items leave when the condition clears |
| Severity entry | Derived only | Cannot rot or be argued with |
| Staff pool | `Role::canAccessPanel()` | One definition, shared with Filament |
| Suppression expiry | Tied to the condition being fixed | Cannot outlive its own problem |
| Concurrent actions | Block, and name who won | Receipts must not be silently overwritten |
| Drill-down | Filament resource pages | Reuse machinery over rebuilding it |