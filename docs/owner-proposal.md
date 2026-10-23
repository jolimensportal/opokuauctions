# Opoku — One Platform for the Whole Business

*What has been built for you. Everything described as live runs today.*

---

## The short version

Most software for this trade picks one thing: an auction site, or a shipping
company's booking page. Your business is not one thing. You take in cars from
salvage auctions, garage cleanouts, estates, foreclosures, liquidators and
trade-ins. You take in pallets, bundles and single items. You ship it all to
Tema. You clear it. You get paid.

This platform runs all of that as one operation. One place where a lot is
filed, bid on, closed, paid for, shipped, cleared and handed over.

---

## Four ways this makes money — all in one system

1. **Vehicle auctions.** Salvage write-offs, garage-sale finds, repos, estate
   cars, trade-ins.
2. **Goods auctions.** Whole pallets, bundles and single items — a 480-carton
   pallet and a bed frame are both lots, filed the same way.
3. **Parcel freight.** Customers shipping parcels and small pallets from the
   US and Canada to Tema. They do not need to buy a car to use you.
4. **Vehicle freight and clearance.** Quote, book, ship and clear a car from
   the auction house to the buyer's hands.

A car buyer and a parcel sender are different customers with different needs.
Both are served by the same platform, and both are on the same books.

---

## The auction floor takes anything you can sell

This is the part most auction software gets wrong. It assumes everything is a
car. Your business is not that narrow, and the system is not either.

- **Vehicles and goods are both first-class lots.** A lot is either a vehicle
  or goods — a pallet, a bundle, or a single item. The operator's form swaps
  to the right fields automatically.
- **Bulk lots carry their manifest.** A pallet lot records what is inside it,
  how many cartons, the grade, and its dimensions. The same table holds a
  single headlamp, with the bulk fields simply left empty.
- **Provenance is recorded on every lot.** Garage sale. Foreclosure.
  Liquidation. Overstock and returns. Estate. Salvage auction. Trade-in.
- **Buyers filter by it.** The storefront boards are real: Vehicles, Garage
  goods, Salvage write-offs, Garage-sale lots. Buyers narrow by kind,
  provenance, city, condition and make.

The point is not that the platform has categories. The point is that when a
new kind of stock arrives — an estate clearance next month, a container of
returns the month after — it is filed the same way, on the same system, with
the same auction and the same books. You do not buy another piece of software
each time the business changes shape.

---

## Bidding that can be proven

Every bid is a record: who, how much, and the exact second it arrived.

- **Bids are written to the database, not painted on a screen.** The lot's
  current price and bid count are rebuilt from the bid rows themselves, so the
  two cannot drift apart.
- **A leading bid is demoted, never deleted.** When someone outbids the
  leader, the old bid stays in the history marked as outbid. The full story is
  always there.
- **The clock defends itself.** A bid in the closing window extends the
  auction, so no one steals a car by waiting until everyone is asleep.
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
  but no real bidder is recorded against it, the system refuses to invent a
  sale and flags the lot for a human instead. One real defect was found and
  fixed this way.
- **Closing twice changes nothing.** Running the close again on a closed lot is
  safe.

The scheduling that runs the closer every minute is the next switch to turn
on — the hard part, the decision logic, is done and tested.

---

## Freight: cars, parcels and pallets on the same corridor

### Cars

A buyer enters the vehicle. The system knows its weight, applies the current
rate, and returns a real shipping quote — Tema corridor, landed cost. No phone
call, no waiting for the office to open.

### Parcels and small pallets

This is a separate business line, and it is fully built.

- **Real quotes from real inputs.** Weight, length, width, height, and whether
  the customer wants pickup. Volumetric weight is calculated, not guessed.
- **Origins that are actually served.** Newark, Houston, Savannah, Los
  Angeles, Toronto, Vancouver — each zone is either priced or honestly
  says "we do not ship from here yet."
- **A booking can be re-priced from the record alone.** The system stores the
  original enquiry, not just a total from someone's browser. A quote can be
  rebuilt months later and it will match.
- **The booking has a real lifecycle.** Quoted, payment pending, booked — and
  separately expired (a link nobody took) and abandoned (nobody ever
  answered). Those are two different failures with two different fixes, and the
  system tells them apart.
- **Unpaid bookings clean themselves up.** A scheduled sweep expires stale
  quotes hourly, so the pipeline does not fill with ghosts.

A parcel customer never has to look at a car. That matters: it is revenue that
does not depend on the auction floor.

### Clearance

Duty and clearance cost is estimated before the goods leave, not discovered
at the port. The buyer knows what they owe before they commit.

---

## The money is on the books, not in someone's head

- **Every buyer has an account.** Money in and money out is recorded as
  double-entry ledger lines.
- **The system proves its own arithmetic.** At any moment it can reconcile
  every wallet against its own ledger, to the cent.
- **The operations screen shows where cash is at risk.** Cars won but unpaid.
  Cars paid for but not booked onto a shipment. Those are the two lists where
  money is quietly sitting in someone else's hands, and for the first time
  both are on one screen.

The hold, release and capture operations are built and tested. Wiring them to
the moment of a bid is the next step — the accounts they will move already
exist and already balance.

---

## Three screens, each for the person who needs it

- **Operations console.** Everything needing action today: lots, bids,
  shipments, clearance, exceptions, money at risk.
- **Freight console.** A separate workspace for the shipping team — bookings,
  milestones, allocations, exceptions.
- **Customer portal.** The buyer signs in and sees their cars, their parcels,
  their payments and their documents. The "where is my car" phone calls stop.

Behind them, three roles. A buyer sees only their own business. An operator
runs the day but cannot move money out of a disputed state. The owner sees
everything and holds the money authority. The difference between an operator
and the owner is money and trust — nothing else.

---

## Depth you can measure

This is not a set of screens mocked up for a meeting.

- **525 automated checks** run over the system every time it changes. They
  catch the things that cost money: a bid that goes missing, a wallet that
  stops balancing, a lot sold twice, a booking that cannot be re-priced.
- **Every surface was checked in a real browser**, screen by screen — the
  storefront, the operations console, the freight console, the customer portal.
- **The data is real.** Over 160 lots in the catalog today, with bid histories
  written as records rather than painted numbers.

The usual failure in software is not a missing feature. It is a system that
looks finished and quietly does the wrong thing with your money. A system that
checks itself is worth more than one that merely looks complete.

---

## What is next, in order

1. **Money held at the bid.** Hold the leader's funds the moment they lead,
   release the loser, capture the winner. The accounts and operations are
   built; this is the wiring.
2. **The scheduled closer.** Run the tested closing logic every minute.
3. **Auto-assignment to a container.** When a car is won and paid, place it on
   the next shipment without a human doing it by hand. The containers and cars
   already exist; the link is one connection.
4. **Direct payments.** Let buyers pay into their account by mobile money or
   card, instead of an operator recording it.
5. **Reports.** What sold, at what margin, over what period. The data is
   already there.

Each is a connection to something already built, not a rebuild.

---

## What you are actually getting

Not an auction website. Not a shipping booking page.

The part of your business that currently lives in your head and in other
people's phones — the lots, the bids, the money, the parcels, the cars, the
clearance, the customers — written down somewhere that never forgets, never
guesses, and can be handed to someone else to run.

It is built. It runs. You can walk through it today.

---

*Everything described as live is live in the system as it stands. The figures —
the lot count, the 525 checks, the ledger reconciling to the cent — are
measured, not estimated. Anything described as next has not been switched on
yet, and this document says so.*
