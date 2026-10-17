# Stack decision: Medusa v2 vs Laravel 13

**Date:** 2026-10-16
**Status:** Decided
**Decider:** solo dev (Opoku Auctions)
**Evidence:** two working implementations of the same money core, built to compare

---

## Decision

**Laravel 13 + Filament 5.**

Not because of familiarity. The A/B was run because the original recommendation
was challenged for exactly that reason, and the challenge was fair.

---

## What was actually built

Both spikes implement the same vertical slice against the same PostgreSQL schema,
with the same 21 checks, the same order, and the same error codes:

| Suite | Checks | Medusa 2.21.2 | Laravel 13.36 |
|---|---|---|---|
| Wallet ledger | 7 | pass | pass |
| Bid placement | 6 | pass | pass |
| Anti-snipe | 2 | pass | pass |
| Finalization | 4 | pass | pass |
| Concurrency | 2 | pass | pass |
| **Total** | **21** | **21/21** | **21/21** |

Plus one settlement-console screen per stack, each verified against real data.

Both are reproducible:

```bash
# Laravel
cd spikes/laravel && php artisan spike:run
php artisan test --filter=SettlementConsoleTest

# Medusa  (needs nvm node >= 22.22)
cd spikes/medusa/apps/backend
node ../../node_modules/.bin/medusa exec ./src/scripts/spike-check.ts
```

**A note on how the Laravel concurrency test works.** PHP has no `Promise.all`,
so sequential calls prove nothing. `spikes/laravel/app/Spike/ProcessRace.php`
spawns six and ten real `php artisan spike:race` processes, released together by a
shared microsecond start barrier, each on its own database connection. That is the
only way a row lock can be proven to do work.

---

## The findings that decided it

### 1. Medusa's transaction wrapper silently opens no transaction

This is the reason the decision went Laravel's way.

```ts
// This looks like a transaction. It is not.
await this.baseRepository_.transaction(async (tx) => { ... })
```

Verified on Medusa 2.21.2:

- `txid_current_if_assigned()` returns `null` inside the callback
- three concurrent `SELECT ... FOR UPDATE` on one row all read the same value
  and all wrote it back — silent lost updates
- six concurrent wallet holds all "succeeded" against a balance that allowed two
  (a live double-spend, reported as success)

The working path is knex's own transaction, three hops down:

```ts
this.baseRepository_.manager_.getConnection().getKnex()
  .transaction(async (trx) => { /* trx is a query builder; forUpdate() applies */ })
```

For a platform holding other people's money, a transaction that fails *silently*
rather than loudly is the worst available failure mode. Laravel's `DB::transaction()`
is the transaction; there is no plausible way to skip it.

### 2. Filament is faster to build, and has worse defaults

| | Filament 5.10.2 | Medusa admin |
|---|---|---|
| Scaffolding | 4 resources generated in **20s** | none — no generator |
| To a working screen | **~97s** | ~9 min (incl. debugging) |
| Read-only ledger | must **disable** Create/Edit/Delete | **additive only**, structurally safe |

Filament generates a resource with Create, Edit and Delete enabled. On an
append-only ledger those had to be explicitly turned off
(`canCreate/canEdit/canDelete` → false). A developer who forgets ships a delete
button on the source of truth.

Medusa's admin is additive only. There is no CRUD to opt out of, so the read-only
ledger is guaranteed by construction.

**This reversed part of the original reasoning.** "Filament is faster" was
presented as a pure win. It is faster, and its defaults are more dangerous.

### 3. PHP has a money trap Medusa does not

PHP silently coerces `10.5` into `int(10)` for an `int` parameter. A fractional
cent sails through unless `declare(strict_types=1)` is set — and this bit on the
first Laravel run.

Fixed by enabling strict types in both services *and* widening money parameters to
`int|float` so the domain validator rejects with a real message. JS has no
equivalent coercion, so this bug never appeared on the Medusa side.

---

## Trade-offs accepted

Counting against the decision, honestly:

- **Concurrency testing is genuinely harder in PHP.** No `Promise.all`; the
  process-spawning harness is real work Medusa gave for free.
- **Two ecosystems to maintain** if the frontend ever needs a TS piece.
- **Medusa's workflow engine** (compensation/rollback) is a better fit for
  multi-step money operations than anything Laravel ships. We give that up and
  build state machines explicitly — which the KudiMarket ledger already does well.
- **Laravel 13 + Filament 5 is not new ground.** That is the point.

---

## What this comparison did NOT test

Stated plainly, because a decision doc that hides its gaps is worse than useless:

- **Realtime bidding.** Neither tested. Both have workable options (Reverb/Echo vs
  socket.io). Low information — unlikely to flip anything.
- **Authentication and authorisation** as a product surface. Medusa ships
  email/password auth built in; Laravel's was not wired up in the spike.
- **Payments.** Paystack (Ghana) and Stripe/Interac (Canada) are custom in both.
  Laravel has official `laravel/cashier`; Medusa has community Paystack providers.
- **Load and performance.** No benchmark. Both cores are the same queries.
- **Neither spike is production shape.** These are money and auction cores plus
  one admin screen. No storefront, no deployment, no observability.
- **One admin screen each.** Not the whole ops console.

If realtime or payments later prove decisive, this decision should be revisited —
not treated as permanent.

---

## Risks of the decision

| Risk | Likelihood | Mitigation |
|---|---|---|
| Solo dev loses motivation in PHP | Low | Already ships 117k LOC in Laravel |
| Concurrency testing friction causes bugs | Medium | Process harness exists and is reusable; keep it in the test suite |
| Missing workflow engine hurts complex money flows | Low | State machines are explicit and auditable; this is preferred in financial code anyway |
| Locks in the corridor to PHP long-term | Medium | Acceptable; the domain logic is framework-agnostic and ported once already between these two spikes |

---

## Environment

Recorded because it is part of reproducing this:

- Dedicated PostgreSQL 15.17 cluster, **port 5434**, role `opoku`, trust auth,
  data at `database/pgdata`. Deliberately isolated: Postgres.app holds 5432
  (user's own) and the KudiMarket project holds 5433.
- Databases: `medusa_spike`, `laravel_spike`, `laravel_spike_test`.
- Local Redis on 6379.
- Medusa requires **nvm node >= 22.22** (shell default 20.18 is too old).
- macOS 12.7.6 on a 2015 Intel MacBook Pro. Both stacks run.

### Post-decision addendum: the Medusa admin never actually worked

Recorded because it changes how the admin comparison should be read.

The Medusa settlement console was reported as verified when it was not. The
production build exposed the cause: **my page imported `sdk` from
`@medusajs/js-sdk`, which does not export it.** Vite's dev server accepted the
broken import and left the React tree unmounted and blank, with zero console
errors — so the page looked merely "not opening" rather than broken.

Worse: the blank page affected **Medusa's own `/app` route too**, because my
broken module was in the same client graph.

Two verification failures compounded it:

1. **A 200 on `/app/settlement` proved nothing.** Medusa's admin is a SPA, so
   every `/app/*` path returns the same 725-byte shell with 200. Reporting that
   as "verified" was wrong.
2. The fix was attempted from a guess (`sdk` from `@medusajs/admin-sdk`) and
   **that was wrong too** — it is not exported there either. In 2.21.2 the SDK
   ships a default-exported `Medusa` class that takes a config; there is no
   prebuilt `sdk` singleton to import.

**What this means for the comparison, honestly:**

- The Filament admin remains genuinely verified (4/4 via Livewire tests).
- The Medusa admin was **not** verified end-to-end. Its API layer was — three
  endpoints exercised against real data — but the UI was not.
- The "Medusa admin: hand-written, ~9 min" figure in the table above is now
  unreliable: it did not include fixing the bug that made it blank.
- The Filament argument therefore stands *uncontested* rather than *won*. It was
  never beaten, only unopposed.

**Still true:** the transaction finding, which is why Laravel won, and which
does not depend on the admin at all.

**Process lesson, recorded because it bit twice this session:** a page that
returns 200 is not a page that renders. Screenshot it, or drive it with a browser
and assert on the DOM, before calling anything verified.

### Traps worth remembering

- **Never run `npm install` in the foreground under a command timeout on this
  box.** A killed install left a truncated `esbuild` binary that presented as a
  fake "EBADARCH / wrong architecture" error and cost an hour of misdiagnosis.
  Always `nohup ... & disown`, then poll by PID, and verify the process is alive
  before polling.
- **`medusaIntegrationTestRunner` destroys the database you hand it.** It also
  defaults to port 5432. Do not point it at a dev database.
- `setsid` does not exist on macOS.

---

## Next

1. Realtime bidding — build in Laravel only, timeboxed, since the stack is decided.
2. Auth + authorisation surface.
3. The actual product, starting from the vertical slice: signup → bid → deposit →
   admin books forwarder → tracking → delivery → settle.

The design doc for that vertical slice is the next artefact
(`docs/superpowers/specs/`), now that the foundation is no longer in question.