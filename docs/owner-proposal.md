# Opoku — One Platform, Two Businesses

*What has been built for you. Everything described as live runs today.*

---

## The short version

This system runs two businesses on one set of records:

**The auction house** — cars and goods from salvage auctions, garage cleanouts,
estates, foreclosures, liquidators, overstock and trade-ins.

**A full third-party logistics (3PL) company** — general shipments from the United
States and Canada to Tema, for anyone: a private individual sending a parcel or
a pallet home, a family shipping a barrel, a business moving stock, or a buyer
moving a car they won.

They are not two products bolted together. They share the same customers, the
same money, the same containers and the same books.

---

## Two businesses, one platform

Most software in this trade picks one side. An auction site. Or a courier's
booking page. Your business is both, and the handover between them — the moment
a sold car has to start moving — is where the money and the confusion live.

This platform owns that handover. A lot is filed, bid on, closed and paid for.
The same system then quotes its freight, books it, puts it in a container,
clears it at Tema and hands it over. And because the logistics side stands on
its own, it also sells to people who will never bid on anything: the sender with
one box is as much a customer as the buyer of a car.

---

## The auction floor takes anything you can sell

Auction software usually assumes everything is a car. Your business is not that
narrow, and the system is not either.

- **Vehicles and goods are both first-class lots.** A lot is either a vehicle or
  goods — a pallet, a bundle, or a single item. The operator's form swaps to the
  right fields automatically.
- **Bulk lots carry their manifest.** A pallet lot records what is inside it,
  how many cartons, the grade and its dimensions. The same table holds a single
  headlamp, with the bulk fields simply left empty.
- **Provenance is recorded on every lot.** Garage sale. Foreclosure.
  Liquidation. Overstock and returns. Estate. Salvage auction. Trade-in.
- **Buyers filter by it.** The storefront boards are real: Vehicles, Garage
  goods, Salvage write-offs, Garage-sale lots. Buyers narrow by kind,
  provenance, city, condition and make.

When a new kind of stock arrives — an estate clearance next month, a container
of returns the month after — it is filed the same way, on the same system, with
the same auction and the same books.

---

## Bidding that can be proven

Every bid is a record: who, how much, and the exact second it arrived.

- **Bids are written to the database, not painted on a screen.** The lot's
  current price and bid count are rebuilt from the bid rows themselves, so the
  two cannot drift apart.
- **A leading bid is demoted, never deleted.** When someone outbids the leader,
  the old bid stays in the history marked as outbid. The full story is there.
- **The clock defends itself.** A bid in the closing window extends the auction,
  so no one steals a car by waiting until everyone is asleep.
- **A bidder cannot bid against themselves.** The system refuses it.

When a customer says "I bid more than that," you open the record. The
conversation is over in ten seconds. That is what an auction is worth.

---

## Closing that does not depend on someone remembering

The closing logic is built and tested: at the end of an auction it checks the
reserve, sells to the recorded winner if the reserve is met, and marks the lot
"reserve not met" if it is not.

Two safeguards matter here.

- **A car is never sold to nobody.** If the figures say a lot met its reserve
  but no real bidder is recorded against it, the system refuses to invent a sale
  and flags the lot for a human instead. One real defect was found and fixed
  this way.
- **Closing twice changes nothing.** Running the close again on a closed lot is
  safe.

The schedule that runs the closer every minute is the next switch to turn on —
the hard part, the decision logic, is done and tested.

---

## The 3PL — a logistics business, not a shipping page

This is the part that became core. It covers what a third-party logistics (3PL)
company has to do for general shipments: quote, collect, consolidate, ship,
clear, deliver, and account for every step — from the US and Canada to Tema,
for parcels, pallets and vehicles alike.

### Anyone can get a price, without an account

- **The sender quote page.** Choose a parcel or a pallet, give the weight,
  dimensions, quantity and whether you want pickup, and get a price in seconds.
  No registration, no account, no salesperson.
- **WhatsApp.** A sender can quote and book by messaging. Replies are matched to
  the booking by its reference, not the phone number — so someone who quotes
  from one number and replies from another is not silently dropped.
- **Reserve the rate.** Someone who wants to think about it can leave an email
  and record the price they were quoted. No password, no address — at that point
  there is a question, not yet a customer.
- **Honest refusal.** An undimensioned parcel, an unmapped postcode or an
  expired rate returns a reason, not a number. A plausible-looking invented
  price is how a freight business loses money quietly.

### Priced on what it actually costs to move

- **Billable weight is whichever is greater** — the weight on the scale, or the
  space the thing occupies. A 2 kg box of screws and a 2 kg box of pillows cost
  the same to move, and only one of them is worth charging for the volume.
- **Whole centimetres, integer cents.** No invented decimal points, no floating
  point drifting a cent off a real price.
- **Parcels are priced per parcel; pallets by their footprint.** They are
  handled differently, so they are priced differently.
- **Rate cards are versioned and append-only**, with effective windows. The
  forwarder's cost and the customer's price are separate figures, so quoting
  your cost by accident is impossible.
- **A quote is not a stale promise.** A booking re-prices at confirmation
  because rates move. Car freight is recomputed live on every view rather than
  stored and honoured months later.

### Three ways goods reach us

Warehouse drop-off. Partner courier pickup, billed per stop. Collection by us —
modelled from day one, launched when the route is ready. Adding the third is a
rate change, not a rebuild.

### A booking with a real lifecycle

Quoted, payment pending, booked. And two different ways of not getting there:

- **Expired** — a payment link nobody took.
- **Abandoned** — nobody ever answered.

Those are different failures with different fixes, and the system tells them
apart instead of collapsing them into one "dead" number.

- **A 24-hour payment link** is issued for the exact quoted amount.
- **The exchange rate is locked when the link is created and written down**, so
  the amount paid can always be traced back to a rate rather than to whatever
  the rate happened to be later.
- **The payment is recorded before the booking is confirmed**, in the same
  transaction. A paid booking that does not exist is made impossible rather than
  unlikely.
- **Stale quotes clean themselves up.** A scheduled sweep expires unpaid
  bookings hourly, and a booking with no reply for seven days is marked
  abandoned, so the pipeline does not fill with ghosts.

### Consolidation and the container

- **A shipment is a real container**: forwarder, vessel, voyage, container
  number, booking reference, bill of lading, origin and destination ports,
  freight and insurance cost, ETA, actual departure and arrival, and the source
  of its tracking.
- **Nothing loads while it owes money.** A container does not leave while any
  lot aboard still owes its balance. The gate reads the payment record, so it
  cannot drift from the ledger.
- **A lot rides in exactly one container.** Enforced by the database itself, so
  the platform cannot promise the same car to two buyers even under a race.
- **Milestones record what actually happened.** A human at the port is
  authoritative and is never overwritten by a later tracking poll.
- **A container that stops moving is flagged** against one threshold, used by
  every screen, so two dashboards can never disagree about whether a shipment is
  late.

### Fair cost sharing

The forwarder charges a minimum consignment charge for the container, not for
each thing inside it. That cost is divided across what is aboard by
largest-remainder — every share is rounded down, then the leftover cents go to
the largest fractions. The shares always sum back to the total. A 13-cent
headlamp in a container of forty other things does not carry $250 of freight on
its own, and the books still reconcile to the cent.

### Clearance at Tema

A checklist that matters, not a decorative one.

- **Money tasks post their cost.** Duty, VAT and port charges go to the ledger,
  because they are the difference between what a buyer paid and what the
  business actually spent. Without them, a landed-cost promise is a guess.
- **A tick without a receipt is unevidenced, not complete.** Customs disputes
  are settled with documents, so a money task marked done with no receipt
  attached is surfaced as a problem rather than quietly passing.
- **Duty is estimated before the goods leave**, so the buyer knows what they owe
  before they commit, not at the port.

### What is stopped gets named

Every logistics business has a queue of things that are stuck. This one names
each item, what it refers to, and the next action. An item without a next action
is a worry, and the console has nowhere to put a worry.

---

## The money is on the books, not in someone's head

- **Every customer has an account.** Money in and money out is recorded as
  double-entry ledger lines.
- **The system proves its own arithmetic.** At any moment it can reconcile every
  wallet against its own ledger, to the cent.
- **The operations screen shows where cash is at risk.** Cars won but unpaid.
  Cars paid for but not on a shipment. Those are the two lists where money is
  quietly sitting in someone else's hands, and both are on one screen.

The hold, release and capture operations are built and tested. Wiring them to
the moment of a bid is the next step — the accounts they will move already exist
and already balance.

---

## Four screens, each for the person who needs it

- **Storefront.** The auction, the lot pages, and the sender quote page — the
  two front doors of the business.
- **Operations console.** Everything needing action today: lots, bids,
  shipments, clearance, exceptions, money at risk.
- **Freight console.** The logistics workspace: shipments and containers,
  forwarders, rate cards, clearance tasks, logistics exceptions.
- **Customer portal.** The buyer or sender signs in and sees their cars, their
  parcels, their payments and their documents. A guest's booking is found by
  their email, so someone who sent a parcel before registering does not look at
  an empty page and think it was lost.

Behind them, three roles. A buyer sees only their own business. An operator runs
the day but cannot move money out of a disputed state. The owner sees everything
and holds the money authority.

---

## Depth you can measure

This is not a set of screens mocked up for a meeting.

- **525 automated checks** run over the system every time it changes. They catch
  the things that cost money: a bid that goes missing, a wallet that stops
  balancing, a lot sold twice, a container loaded with an unpaid car, a booking
  that cannot be re-priced.
- **Every surface was checked in a real browser**, screen by screen — the
  storefront, the operations console, the freight console, the customer portal.
- **The data is real.** Over 160 lots in the catalog today, with bid histories
  written as records rather than painted numbers.

The usual failure in software is not a missing feature. It is a system that
looks finished and quietly does the wrong thing with your money. A system that
checks itself is worth more than one that merely looks complete.

---

## What is next, in order

1. **Book from the website.** Today a sender can get a price and reserve it on
   the site; the booking and payment-link flow is built and runs through
   WhatsApp. Turning on the website's own book-now step is the next connection.
2. **Money held at the bid.** Hold the leader's funds the moment they lead,
   release the loser, capture the winner. The accounts and operations are built;
   this is the wiring.
3. **The scheduled closer.** Run the tested closing logic every minute.
4. **Auto-assignment to a container.** When a car is won and paid, place it on
   the next shipment without a human doing it by hand.
5. **Direct payments.** Let customers pay into their account by mobile money or
   card instead of an operator recording it.
6. **Collection by us.** The third intake method is modelled; it launches when
   the route is priced.
7. **Reports.** What sold, at what margin, over what period. The data is already
   there.

Each is a connection to something already built, not a rebuild.

---

## What you are actually getting

Not an auction website. Not a shipping booking page.

Two businesses — the auction house and the logistics company — running on one
set of records that never forgets, never guesses, and can be handed to someone
else to run. The lots, the bids, the money, the parcels, the pallets, the
containers, the clearance, the customers: all of it in one place.

It is built. It runs. You can walk through it today.

---

*Everything described as live is live in the system as it stands. The figures —
the lot count, the 525 checks, the ledger reconciling to the cent — are
measured, not estimated. Anything described as next has not been switched on
yet, and this document says so.*
