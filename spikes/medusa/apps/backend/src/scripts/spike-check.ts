import assert from "node:assert"

/**
 * OpokuAuctions spike check — stack A (Medusa v2).
 *
 * Runs the real module services against the real Postgres cluster. No mocks, no
 * in-memory doubles. Every assertion here has an identical twin in the Laravel
 * spike; if one of them is hard to write in a stack, that is the finding.
 */

let passed = 0
let failed = 0
const failures: string[] = []

async function check(name: string, fn: () => Promise<void>) {
  try {
    await fn()
    passed++
    console.log(`  PASS  ${name}`)
  } catch (err: any) {
    failed++
    const msg = err?.message?.split("\n")[0] ?? String(err)
    failures.push(`${name}: ${msg}`)
    console.log(`  FAIL  ${name}`)
    console.log(`        ${msg}`)
  }
}

// `medusa exec` boots the app and hands us its container. Booting a second
// copy here would double-register subscribers and blow up.
export default async function spikeCheck({ container }: { container: any }) {
  const lots: any = container.resolve("lots")
  const wallet: any = container.resolve("wallet")

  console.log("Running checks against the live Medusa container.\n")

  // Unique-ish suffix so repeated runs never collide on ids or emails.
  const run = `r${Date.now().toString(36)}`
  const money = (n: number) => ({ amount: n, currency_code: "usd" })

  /**
   * Assert a call throws with a given error code.
   *
   * MedusaError puts the CODE in `.message` and the human sentence elsewhere,
   * so codes are the stable thing to assert on. Laravel will assert the same
   * codes, which keeps the two spikes directly comparable.
   */
  const throwsCode = async (fn: () => Promise<any>, code: string) => {
    try {
      await fn()
    } catch (err: any) {
      const actual = String(err?.message ?? err)
      assert.ok(
        actual.includes(code),
        `expected error code "${code}", got "${actual}"`
      )
      return
    }
    throw new Error(`expected a throw with code "${code}", but it succeeded`)
  }

  /** Push a lot's close time into the past so finalization can run. */
  const expire = async (lotId: string) => {
    await wallet.baseRepository_.manager_
      .getConnection()
      .getKnex()("lot")
      .where({ id: lotId })
      .update({ ends_at: new Date(Date.now() - 1000) })
  }

  const makeLot = async (overrides: Record<string, any> = {}) => {
    const now = Date.now()
    return await lots.createLots({
      vin: `VIN-${run}`,
      year: 2016,
      make: "Honda",
      model: "Accord",
      city: "Toronto",
      photos: [],
      starting_bid: 100_00,
      reserve_price: 500_00,
      bid_increment: 25_00,
      starts_at: new Date(now - 60_000),
      ends_at: new Date(now + 3600_000),
      status: "UPCOMING",
      currency_code: "usd",
      ...overrides,
    })
  }

  // ---------------------------------------------------------------- wallet ---

  console.log("wallet ledger")

  await check("deposit credits balance and writes one ledger row", async () => {
    const u = `${run}-dep`
    await wallet.ensureWallet(u)
    const res = await wallet.deposit(u, money(1000_00), { id: "pay_1" })
    assert.equal(res.available, 1000_00)
    assert.equal(res.held, 0)

    const entries = await wallet.listLedgerEntries({ user_id: u })
    assert.equal(entries.length, 1)
    assert.equal(entries[0].type, "DEPOSIT")
    assert.equal(entries[0].balance_after, 1000_00)
  })

  await check("hold moves money between buckets, total unchanged", async () => {
    const u = `${run}-hold`
    await wallet.ensureWallet(u)
    await wallet.deposit(u, money(1000_00))
    const res = await wallet.hold(u, money(400_00), { id: "b1" })
    assert.equal(res.available, 600_00)
    assert.equal(res.held, 400_00)
    assert.equal(res.available + res.held, 1000_00)
  })

  await check("cannot hold more than the buyer has", async () => {
    const u = `${run}-poor`
    await wallet.ensureWallet(u)
    await wallet.deposit(u, money(100_00))
    await throwsCode(() => wallet.hold(u, money(500_00), { id: "x" }), "INSUFFICIENT_FUNDS")
  })

  await check("release returns held funds to available", async () => {
    const u = `${run}-rel`
    await wallet.ensureWallet(u)
    await wallet.deposit(u, money(1000_00))
    await wallet.hold(u, money(400_00), { id: "b1" })
    const res = await wallet.release(u, money(400_00), { id: "outbid" })
    assert.equal(res.available, 1000_00)
    assert.equal(res.held, 0)
  })

  await check("capture removes held funds permanently", async () => {
    const u = `${run}-cap`
    await wallet.ensureWallet(u)
    await wallet.deposit(u, money(1000_00))
    await wallet.hold(u, money(400_00), { id: "b1" })
    const res = await wallet.capture(u, money(400_00), { id: "settle" })
    assert.equal(res.available, 600_00)
    assert.equal(res.held, 0)
  })

  await check("wallet reconciles against its own ledger", async () => {
    const u = `${run}-rec`
    await wallet.ensureWallet(u)
    await wallet.deposit(u, money(1000_00))
    await wallet.hold(u, money(300_00), { id: "b1" })
    await wallet.hold(u, money(200_00), { id: "b2" })
    await wallet.release(u, money(200_00), { id: "b2-out" })
    await wallet.capture(u, money(300_00), { id: "win" })

    const r = await wallet.reconcile(u)
    assert.equal(r.ok, true, "wallet drifted from ledger")
    assert.equal(r.ledger.available, r.wallet.available)
    assert.equal(r.ledger.held, r.wallet.held)
  })

  await check("rejects a fractional cent", async () => {
    const u = `${run}-frac`
    await wallet.ensureWallet(u)
    await throwsCode(
      () => wallet.deposit(u, { amount: 10.5, currency_code: "usd" }),
      "INVALID_AMOUNT"
    )
  })

  // ------------------------------------------------------------------ bids ---

  console.log("\nbid placement")

  await check("first bid is accepted at the starting price", async () => {
    const lot = await makeLot()
    await lots.openLot(lot.id)
    const res = await lots.placeBid({
      lotId: lot.id, bidderId: `${run}-alice`, bidderEmail: "a@e.com", amount: 100_00,
    })
    assert.equal(res.lot.current_bid, 100_00)
    assert.equal(res.lot.bid_count, 1)
  })

  await check("bid below the increment is rejected", async () => {
    const lot = await makeLot()
    await lots.openLot(lot.id)
    await lots.placeBid({ lotId: lot.id, bidderId: `${run}-a1`, bidderEmail: "a@e.com", amount: 100_00 })
    await throwsCode(
      () => lots.placeBid({ lotId: lot.id, bidderId: `${run}-b1`, bidderEmail: "b@e.com", amount: 110_00 }),
      "BID_TOO_LOW"
    )
  })

  await check("highest bidder cannot re-bid on their own lot", async () => {
    const lot = await makeLot()
    await lots.openLot(lot.id)
    const u = `${run}-self`
    await lots.placeBid({ lotId: lot.id, bidderId: u, bidderEmail: "a@e.com", amount: 100_00 })
    await throwsCode(
      () => lots.placeBid({ lotId: lot.id, bidderId: u, bidderEmail: "a@e.com", amount: 200_00 }),
      "ALREADY_HIGHEST"
    )
  })

  await check("outbidding demotes the previous leader", async () => {
    const lot = await makeLot()
    await lots.openLot(lot.id)
    await lots.placeBid({ lotId: lot.id, bidderId: `${run}-a2`, bidderEmail: "a@e.com", amount: 100_00 })
    await lots.placeBid({ lotId: lot.id, bidderId: `${run}-b2`, bidderEmail: "b@e.com", amount: 125_00 })

    const bids = await lots.listBids({ lot_id: lot.id })
    const a = bids.find((b: any) => b.bidder_id === `${run}-a2`)
    const b = bids.find((b: any) => b.bidder_id === `${run}-b2`)
    assert.equal(a.status, "OUTBID")
    assert.equal(b.status, "ACCEPTED")
  })

  await check("replaying an idempotency key does not double-bid", async () => {
    const lot = await makeLot()
    await lots.openLot(lot.id)
    const key = `${run}-idem`
    const first = await lots.placeBid({
      lotId: lot.id, bidderId: `${run}-i1`, bidderEmail: "a@e.com",
      amount: 100_00, idempotencyKey: key,
    })
    const second = await lots.placeBid({
      lotId: lot.id, bidderId: `${run}-i1`, bidderEmail: "a@e.com",
      amount: 100_00, idempotencyKey: key,
    })
    assert.equal(second.bid.id, first.bid.id)
    const bids = await lots.listBids({ lot_id: lot.id })
    assert.equal(bids.length, 1)
  })

  await check("bid on a closed auction is rejected", async () => {
    const lot = await makeLot()
    await lots.openLot(lot.id)
    await expire(lot.id)
    await throwsCode(
      () => lots.placeBid({ lotId: lot.id, bidderId: `${run}-z`, bidderEmail: "a@e.com", amount: 100_00 }),
      "AUCTION_ENDED"
    )
  })

  // ------------------------------------------------------------ anti-snipe ---

  console.log("\nanti-snipe")

  await check("bid inside the final window pushes the close time out", async () => {
    const lot = await makeLot({ ends_at: new Date(Date.now() + 60_000) })
    await lots.openLot(lot.id)
    const before = new Date(lot.ends_at).getTime()
    const res = await lots.placeBid({
      lotId: lot.id, bidderId: `${run}-sn1`, bidderEmail: "a@e.com", amount: 100_00,
    })
    assert.equal(res.extension, true)
    assert.equal(res.lot.extensions, 1)
    assert.ok(new Date(res.lot.ends_at).getTime() > before, "ends_at did not move")
  })

  await check("bid outside the window does not extend", async () => {
    const lot = await makeLot({ ends_at: new Date(Date.now() + 3600_000) })
    await lots.openLot(lot.id)
    const res = await lots.placeBid({
      lotId: lot.id, bidderId: `${run}-sn2`, bidderEmail: "a@e.com", amount: 100_00,
    })
    assert.equal(res.extension, false)
    assert.equal(res.lot.extensions, 0)
  })

  // --------------------------------------------------------- finalization ---

  console.log("\nfinalization")

  await check("reserve met closes as SOLD", async () => {
    const lot = await makeLot()
    await lots.openLot(lot.id)
    await lots.placeBid({
      lotId: lot.id, bidderId: `${run}-w1`, bidderEmail: "w@e.com", amount: 500_00,
    })
    await expire(lot.id)
    const res = await lots.finalizeLot(lot.id, wallet)
    assert.equal(res.status, "SOLD")
    assert.equal(res.winnerId, `${run}-w1`)
  })

  await check("top bid under reserve closes as RESERVE_NOT_MET", async () => {
    const lot = await makeLot({ reserve_price: 900_00 })
    await lots.openLot(lot.id)
    await lots.placeBid({
      lotId: lot.id, bidderId: `${run}-w2`, bidderEmail: "w@e.com", amount: 100_00,
    })
    await expire(lot.id)
    const res = await lots.finalizeLot(lot.id, wallet)
    assert.equal(res.status, "RESERVE_NOT_MET")
    assert.equal(res.winnerId, null)
  })

  await check("finalizing twice is a no-op", async () => {
    const lot = await makeLot()
    await lots.openLot(lot.id)
    await lots.placeBid({
      lotId: lot.id, bidderId: `${run}-w3`, bidderEmail: "w@e.com", amount: 600_00,
    })
    await expire(lot.id)
    const a = await lots.finalizeLot(lot.id, wallet)
    const b = await lots.finalizeLot(lot.id, wallet)
    assert.equal(a.status, "SOLD")
    assert.equal(b.status, "SOLD")
  })

  await check("cron query finds only expired live lots", async () => {
    const expired = await makeLot({ ends_at: new Date(Date.now() - 1000) })
    await lots.openLot(expired.id)
    const live = await makeLot({ ends_at: new Date(Date.now() + 3600_000) })
    await lots.openLot(live.id)

    const ids = await lots.findExpiredLiveLots()
    assert.ok(ids.includes(expired.id), "expired lot not returned")
    assert.ok(!ids.includes(live.id), "live lot wrongly returned")
  })

  // ----------------------------------------------------------- concurrency ---

  console.log("\nconcurrency")

  await check("six buyers cannot overdraw one wallet", async () => {
    const u = `${run}-race`
    await wallet.ensureWallet(u)
    await wallet.deposit(u, money(100_00))

    // Six simultaneous holds of 50.00 against a 100.00 balance. Exactly two win.
    const results = await Promise.all(
      Array.from({ length: 6 }, (_, i) =>
        wallet.hold(u, money(50_00), { id: `race-${i}` })
          .then(() => "ok")
          .catch(() => "rejected")
      )
    )

    const winners = results.filter((r) => r === "ok").length
    assert.equal(winners, 2, `expected 2 holds to succeed, got ${winners}`)

    const [w] = await wallet.listWallets({ user_id: u })
    assert.equal(w.balance_available, 0)
    assert.equal(w.balance_held, 100_00)

    const r = await wallet.reconcile(u)
    assert.equal(r.ok, true, "wallet drifted from ledger after race")
  })

  await check("ten buyers hammering one lot never lose a bid", async () => {
    const lot = await makeLot()
    await lots.openLot(lot.id)

    const amounts = Array.from({ length: 10 }, (_, i) => 100_00 + i * 25_00)
    await Promise.all(
      amounts.map((amount, i) =>
        lots
          .placeBid({
            lotId: lot.id, bidderId: `${run}-hammer-${i}`, bidderEmail: `b${i}@e.com`, amount,
          })
          .catch(() => undefined) // losing a race for a price is legitimate
      )
    )

    const final = await lots.retrieveLot(lot.id)
    const bids = await lots.listBids({ lot_id: lot.id })

    const highest = Math.max(...bids.map((b: any) => b.amount))
    assert.equal(final.current_bid, highest, "current_bid is not the highest real bid")
    assert.equal(final.bid_count, bids.length, "bid_count does not match bid rows")

    const winning = bids.find((b: any) => b.amount === final.current_bid)!
    assert.equal(final.highest_bidder_id, winning.bidder_id, "leader does not match top bid")

    const accepted = bids.filter((b: any) => b.status === "ACCEPTED")
    assert.equal(accepted.length, 1, "more than one ACCEPTED bid")
    assert.equal(accepted[0].amount, final.current_bid)
  })

  // --------------------------------------------------------------- summary ---

  console.log(`\n${"=".repeat(56)}`)
  console.log(`  ${passed} passed, ${failed} failed, ${passed + failed} total`)
  console.log("=".repeat(56))
  if (failures.length) {
    console.log("\nFailures:")
    failures.forEach((f) => console.log(`  - ${f}`))
  }

  // `medusa exec` keeps the process alive, so exit explicitly.
  process.exit(failed === 0 ? 0 : 1)
}
