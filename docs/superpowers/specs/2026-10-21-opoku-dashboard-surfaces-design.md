# Opoku — dashboard surfaces design

**Date:** 21 October 2026
**Status:** approved by the user on 21 Oct 2026
**Supersedes:** the first three surface candidates (`docs/previews/logistics-1a|1b|1c.html`)

---

## 1. The problem

The platform has three jobs that need three different screens, and today it has
neither the screens nor the data to fill them.

**What exists.** A pricing engine that works (rate cards, origin zones, postcode
bands, billable weight, volumetric rules, locked FX, a Paystack charge in cedi).
A corridor data model that is nearly right (`shipments`, `shipment_milestones`,
`shipment_lots`, `clearance_tasks`, `forwarders`). A Filament console with
resources for lots, bids, wallets, the ledger, clearance tasks and freight rates.
A storefront with a working quote → reserve → WhatsApp handoff.

**What does not exist.**

- No Shipments resource. A container cannot be listed, created or edited anywhere.
- No forwarder records. Nothing can be booked, costed or claimed against a carrier.
- No screen writes a milestone. The three that exist were typed in by hand, and the
  shipment claims a `tracking_source` of ShipsGo that nothing polls.
- No exception workflow. The corridor board can say "silent 3 days"; it cannot say
  who owns the next action, and nothing can be marked done.
- No customer-facing view of any consignment. A buyer's account says
  "Nothing in transit" while their car is in a container.
- `parcel_bookings` has no `user_id`, so a signed-in buyer's parcel cannot be
  found for them — only by email.
- The demo rate card bills at cost. A 200 kg volumetric parcel is quoted $64.00
  against a $64.00 forwarder cost. Zero margin at every weight (deferred, D-1).

**The surface mistake being corrected.** The first candidates put the
storefront's header and footer on what was supposed to be a dashboard, and used
the storefront's flat hairline language instead of the reference screenshot's
card system. Both were wrong: these are applications, not pages.

## 2. The decision

**Three surfaces. Three applications. No shared chrome with the storefront.**

| Surface | Route | Built with | Who |
|---|---|---|---|
| Operations console | `/admin` | Filament, re-skinned | ops, owner |
| Logistics console | `/admin/logistics` | Filament, re-skinned | ops, owner |
| Customer portal | `/account/freight` | our own Blade + Livewire | buyer |

**Why Filament for the two consoles.** It already holds the resources, tables,
forms, the role model and the `canAccessPanel` rule. Re-skinning it is one
stylesheet; rebuilding it is tables, filters, forms, permissions and auth for
two consoles we already have.

**Why the customer portal is ours.** A buyer must never touch Filament. It reads
the same records the console writes, in the same design system, with the
reference's own layout: a narrow icon rail, a standalone top bar, a welcome
banner, three stat tiles, and a "Required action" panel.

**Why two Filament panels and not one.** The two consoles have different
sidebars and different default screens, and Filament's navigation is per panel.
Two panels sharing the `admin` guard gives each its own nav, one login, and no
custom layout work. A small console switch in the top bar links between them.

**Evidence.** Seven logistics SaaS demos were opened and walked (Delix,
CargoPro, We Courier, Movement, Deprixa; the two Olivette previews are 410 Gone
and were read, not walked). All seven agree: each surface is a separate
application, the stat tile is the unit of the dashboard, lists are a filter panel
over dense rows, history is an event timeline, navigation is grouped by
consignment type, and money gets its own panel. Our two ops roles and one
customer portal are a much smaller version of the same shape.

## 3. The design system

One stylesheet, `resources/css/filament/theme.css`, compiled by Vite and
applied to both Filament panels with `->viteTheme('resources/css/filament/theme.css')`.
The customer portal loads the same tokens from a Blade layout.

**Tokens** (already proven in the storefront, `resources/css/app.css`):

- Canvas `#f4f5f7`, cards `#ffffff`, radius `16px`, shadow
  `0 1px 2px rgb(0 0 0 / .04), 0 8px 24px rgb(0 0 0 / .05)`.
- Type: DM Sans for prose, IBM Plex Mono with tabular figures for money,
  references, dates and counts. Both self-hosted.
- Colour: the ink ladder, brick as the only accent. **Filament's Amber primary is
  replaced by brick** (`->colors(['primary' => Color::hex('#d42a29')])` — the
  sRGB value of `brick-500`, and the storefront's own button red, which carries
  white text at 7.83:1), which is what makes the console and the shop read as
  one product.

**Components** (from the reference screenshot, not invented):

- **Stat tile** — circular icon, label, figure, optional delta line. The icon
  disc is ink-950 by default and brick when the figure is an exception count.
- **Welcome banner** — brick gradient, one sentence, three links. Customer
  surfaces only.
- **Pill chip** — mono, uppercase, 10.5px, rounded. States: neutral, brick,
  solid, ok, warn, bad.
- **Card** — white, 16px radius, soft shadow, 1px ink-100 border. Optional
  header row with title, subtitle and actions.
- **Filter panel** — four fields in a row above a table, with a result count and
  a reset.
- **Event timeline** — a node on a rail, the event, who did it, and a
  right-aligned timestamp. Used for consignment history and for activity.
- **Action row** — a disc, a title, a sentence, and a button. The "Required
  action" pattern; the row *is* the task.

**Build risk, and its mitigation.** Tailwind v4's preflight would reset
Filament's own base styles if the theme entry imported the full framework. The
theme entry must import `tailwindcss/theme.css` (tokens and variables, no
preflight) and never `tailwindcss` whole, and it must declare its own
`@source` for the admin Blade files. If the console looks broken in review, this
is the first thing to check.

## 4. Operations console — `/admin`

Replaces today's Filament dashboard page. **Nav:** Marketplace (Dashboard, Lots,
Bids, Categories) · Money (Wallets, Ledger, Payouts) · People (Customers,
Disputes, Roles & permissions).

**Tiles:** lots live · open bids · money owed to us · held or disputed.

**Panels:** *Recent activity* (the event timeline, newest first, from
`shipment_milestones`, `clearance_tasks` and the ledger) and *Required action*
(the items only Ops and Owner can move).

**Required action, on real data, from day one:** the tariff markup (D-1 — the
rate card bills at cost), the missing pickup charge, and the second forwarder.

## 5. Logistics console — `/admin/logistics`

**Nav, grouped by lane, as CargoPro groups by consignment type:**
*Freight we carry* (Containers, Milestones, Manifests, Forwarders, Clearance) ·
*Freight customers send* (Parcels, Pickups, Rate cards, Zones) · *Watch*
(Attention queue, All consignments).

**Tiles (pipeline counters):** in transit · at Tema · parcels booked · need
attention.

**Panels:** *Required action* (silent containers, expired holds, missing
forwarders) and *Money moving* (duty, VAT, port charges, freight and insurance —
each its own line, with a "not recorded" state rather than a zero).

**Consignments table:** reference · lane · contents · route · state · last
movement · money, under a filter panel (reference, lane, state, owner). Both
lanes in one table, because a container and a parcel are the same kind of
record with a different type.

## 6. Customer portal — `/account/freight`

Our own Blade + Livewire page, its own layout, no storefront header or footer.
**Nav:** a narrow icon rail (home, your cars, your parcels, tracking, payments,
invoices, settings, sign out) and a top bar (brand, portal nav, currency,
notifications, avatar). The portal nav may link out to the storefront, but the
storefront never wraps the portal.

**Content:** welcome banner · three tiles (cars arriving, parcels sent, balance)
· *Your freight* (your container, your cars with prices and clearance state, the
timeline) · *Required action* (ID for clearance, collection point, unpaid
parcel) · freight billed.

**How a consignment is found to belong to you:**

- **Cars:** `lot.highest_bidder_id` → `shipment_lots` → `shipment`, with
  `shipment_milestones` for the timeline. The winner link already exists.
- **Parcels:** `parcel_bookings.user_id`, **new nullable column**, set when a
  signed-in buyer books. Guests keep matching on email, as they do now.

## 7. Data model changes

One migration only:

- `parcel_bookings.user_id` — nullable, foreign key to `users`, indexed.

No other schema change is needed. A consignment's state is its latest
milestone's type, and recording a milestone writes both the milestone and
`shipments.status`, so there is one source of truth and no new enum. The
`forwarders` table exists; it has no records.

## 8. The first slice

Five things, in this order. Each is separately demonstrable.

1. **Shipments list and create** — a Filament resource for `shipments`, so a
   container can be opened, listed and created. Nothing else can be built on
   top of a consignment you cannot create.
2. **Forwarder records** — a Filament resource for `forwarders`, plus the first
   real record, so a shipment can be booked and costed.
3. **Milestone capture** — "record a milestone" on a consignment: writes the
   milestone, sets `shipments.status`, stamps who and when. The `source` column
   distinguishes an operator's record from a feed's.
4. **The customer link** — `parcel_bookings.user_id`, set at booking time for a
   signed-in buyer, and the portal's "your parcels" read.
5. **Exception derivation** — silent containers (**three days or more since the
   last milestone**, the rule `CorridorQueries` already uses, so the two screens
   cannot disagree), expired holds, missing forwarder. Computed from data that
   already exists.

**Acceptance for the slice:** an operator can create a container, attach a
forwarder, record a milestone, see the silent-container exception appear, and a
buyer sees their car and their parcel on the portal with a timeline.

## 9. Out of scope

- The **ShipsGo tracking feed**. The console records milestones by hand and says
  so; the `tracking_source` column already exists for when a feed is wired.
- **The tariff markup (D-1)** and the **pickup charge** — surfaced as required
  actions, not solved here.
- **Payouts, disputes and roles** beyond what exists — nav entries only.
- **Rider, driver and merchant apps.** Every one of the seven demos has them;
  none of them are Opoku's business.
- **Rebuilding the storefront.** It stays as it is.

## 10. Testing

- Every console route loads for Ops and Owner, and is refused for a Buyer
  (403). The portal refuses an operator's data.
- The portal shows one buyer only their own consignments, and shows nothing for
  a buyer with none.
- The consignment timeline renders from milestones in the recorded order, with
  the actor and the timestamp.
- Recording a milestone sets the consignment's state, and the silent-container
  exception appears and clears as a milestone is recorded.
- A guest cannot post a parcel booking that claims a `user_id`.
- The existing 441 tests stay green.

## 11. Open questions, deferred

| # | Question | Where it lives |
|---|---|---|
| 1 | What is the retail minimum and the markup on the parcel rate card? D-1, zero margin today. | `docs/superpowers/deferred-work.md` |
| 2 | What does a pickup cost, and who absorbs it? | same register |
| 3 | Is the customer portal a separate domain, or a path on the shop? | this spec, §6 — decided: a path, one session |
| 4 | Does the corridor need a second carrier, or a second forwarder record? | same register |
| 5 | Is a buyer allowed to see a container that carries someone else's car? | this spec — the portal shows the container, the cars in it that are theirs, and nothing else about the rest |

## 12. Artefacts

- `docs/previews/dashboard-shell.css` — the shared stylesheet, written as the
  design system above.
- `docs/previews/logistics-2a.html` — the logistics console.
- `docs/previews/logistics-2b.html` — the operations console.
- `docs/previews/logistics-2c.html` — the customer portal.
- `docs/previews/logistics-candidates.html` — the index, with the research
  summary and the superseded first set.
- Preview server: `python3 -m http.server 8124` inside `docs/previews/`. The
  browser caches both the HTML and the CSS there — append `?v=N` to the page URL
  to see an edit.
