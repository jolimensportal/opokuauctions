# Deferred Work Register — Opoku Auctions

**Opened:** 2026-10-19
**Status:** live register. Every item below is deliberate, with a reason and a trigger.
**Rule:** nothing on this list is started until its trigger is met. Items move up, not sideways.

---

## D-1 — The parcel tariff has no margin. **BLOCKING EVERYTHING.**

**State:** `LogisticsRateSeeder` sets the Newark card to `per_kg_cents = 32` and
`min_charge_cents = 2500`, documented as "the forwarder's current invoice" and
labelled `(DEMO TARIFF)`.

**The finding.** Those are the *cost* figures, and they are applied to the *price*
with the identical formula:

```
price = max(kg × 0.32, 25.00)
cost  = max(kg × 0.32, 25.00)
```

Margin is therefore **exactly zero at every weight**, not thin. Verified:

| kg | our cost | we charge | margin |
|---|---|---|---|
| 0.2 | 25.00 | 25.00 | 0.00 |
| 25 | 25.00 | 25.00 | 0.00 |
| 200 | 64.00 | 64.00 | 0.00 |
| 610 | 195.20 | 195.20 | 0.00 |

**Why it is not cosmetic.** The checkout is live, issues real Paystack links and
charges **GHS 376.25** for a **USD 25.00** quote that is exactly what we pay the
forwarder. Paystack takes 1.5% + GHS 0.30. Every booking loses 100% of its margin.

**The number that matters is the minimum, not the per-kg.** Our cost for anything
under **78 kg** is the flat $25.00 consignment charge, so the per-kg rate is
irrelevant for typical parcels. Only the minimum sets margin.

| Minimum | Gross margin / parcel | % of price |
|---|---|---|
| $45.00 | $20.00 | 44% |
| $65.00 | $40.00 | 62% |
| $95.00 | $70.00 | 74% |

**Trigger:** the owner names a minimum.
**Owner:** unassigned.
**Blocked on:** the owner. This is a commercial decision, not an engineering one.

---

## D-2 — Cold outbound WhatsApp does not clear its own acquisition cost.

**State:** the WhatsApp handoff is live and the checkout works. Neither is being
driven at volume.

**The arithmetic**, at the owner's own 1,000-messages-per-deal ratio:

| | per message | per 1,000 messages = 1 deal |
|---|---|---|
| Meta marketing fee, North America | $0.0250 | $25.00 |
| + Twilio platform | $0.0050 | $30.00 |
| + respond.io (no markup) | $0.0250 | $25.00 |

Against a $25 minimum, acquisition exceeds the deal. Against $175 it is 14–23%.
Even at a $65 tariff, cold outbound leaves $9.01.

**Meta's fee follows the *recipient's* country**, so this rate is North America,
not the Rest of Africa figures that appear in most guides — the senders are in
the USA and Canada.

**Trigger:** a tariff is set (D-1), **and** inbound is already producing
enquiries. Outbound becomes worth doing when the price can absorb $30.
**Not to be done:** cold broadcast campaigns before a tariff exists.

---

## D-3 — WhatsApp provider decision.

**State:** `WhatsAppGateway` is an interface with Twilio bound in
`AppServiceProvider`. The owner has not chosen a provider.

**Cost shape, verified Sept 2026:** Twilio charges $0.005/message plus Meta's
per-template fee. Customer-initiated conversations are **free on Meta's side**
for 24 hours — only business-initiated messages cost Meta anything. Providers
differ only by **markup**:

- **Twilio** — $0.005/message, scales forever
- **respond.io** — no markup, Meta rates direct
- **360dialog** — flat €49/number/month, no markup
- **WATI** — ~25–60% markup, worst per-message

"No unlimited WhatsApp API" exists on the official lane: Meta's rate card is the
floor for every provider.

**Trigger:** an inbound conversation arrives that needs a reply.
**Swapping is one binding** in `app/Providers/AppServiceProvider.php`.

---

## D-4 — Paystack onboarding.

**State:** test keys live in `.env` and a real link was created
(`https://checkout.paystack.com/n4m2f14prrfkxcq`). **Live keys do not exist.**

Paystack is not self-serve: business registration and approval, typically **days
to weeks**. It gates live transactions regardless of code speed.

**Trigger:** now — it has the longest lead time and zero dependencies.

---

## D-5 — SP-2: sender identity and addresses.

The checkout records *what* was sent and *from where*, but **there is no address
on the path**. No destination, no sender identity. Nothing can physically ship
from a booking yet.

**Trigger:** a real paid booking exists with a real customer behind it.
**Note:** SP-2 was briefly described as "booking" in a todo list. It is not.
SP-2 is identity and addresses; the payment is done.

---

## D-6 — SP-3: labels and intake.

The physical handoff after booking. **Trigger:** SP-2 complete.

## D-7 — SP-4: customs and HS codes.

**Trigger:** SP-3 complete.

## D-8 — SP-5: consolidation, tracking, payment.

**Trigger:** SP-4 complete.

---

## D-9 — `quantity` does not affect the price.

The quote form collects a quantity; the quote service ignores it. One unit is
priced regardless.

**Not a bug — an unmade decision.** The two plausible readings differ
substantially above the minimum:

- `quantity × line_total` — N parcels priced individually
- `per-parcel-min × N`, or one consolidation minimum for N boxes

This depends on how the courier bills consolidation, which is a commercial fact
about our forwarder and not something to guess.

**Trigger:** a user actually asks for more than one parcel.

---

## D-10 — AI-assisted mass product import.

**State:** raised by the owner as future work. Not specced, not started.

**Trigger:** the admin lot form (multi-kind Task 6) is complete and the
kind-aware fields are settled — mass import only makes sense once the shape of a
record is stable.

**The obvious pairing:** photo + description → kind, provenance, title,
dimensions. The kind-aware form already forces an operator to choose between
vehicle and goods fields today; that is exactly the tedium bulk import replaces.

---

## D-11 — `landedCostPerLot` splits by quantity, not weight.

A pallet and a bed frame on one container each pay an equal share, despite a
pallet being 30–50× heavier. Pre-existing behaviour, explicitly not changed by
the multi-kind work.

**Trigger:** a commercial decision on whether equal shares are correct.

---

## Register rules

1. **An item moves when its trigger is met, not when it is convenient.**
2. **Nothing is built speculatively.** Several items here were fully specified
   and deliberately not built, because the thing they depend on does not exist.
3. **A trigger being met is evidence, not opinion.** "Someone might want to book"
   is not a trigger. "Three people paid" is.
4. **D-1 outranks everything here.** Every other line is infrastructure for a
   business that currently makes nothing on a parcel.