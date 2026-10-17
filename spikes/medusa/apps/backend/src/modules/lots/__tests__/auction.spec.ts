import { medusaIntegrationTestRunner } from "@medusajs/test-utils"

// Booting the Medusa app for the integration harness takes ~60s on this machine,
// well past Jest's 5s default. Every hook and test inherits this.
jest.setTimeout(180_000)

/**
 * The tests that decide whether this stack is safe for holding other people's
 * money. Every assertion here must also exist, identically, in the Laravel
 * spike. If a test cannot be written the same way in both, that is itself a
 * finding.
 */
medusaIntegrationTestRunner({
  // Run against its own schema so a test run can never touch dev data.
  dbName: "medusa_spike",
  schema: "test",
  testSuite: ({ getContainer }) => {
    // The container only exists after the runner's own beforeAll has booted the
    // app, so services must be resolved here rather than at definition time.
    let lots: any
    let wallet: any

    beforeAll(() => {
      const container = getContainer()
      lots = container.resolve("lots")
      wallet = container.resolve("wallet")
    })

    const money = (n: number) => ({ amount: n, currency_code: "usd" })

    async function makeLot(overrides: Record<string, any> = {}) {
      const now = Date.now()
      const lot = await lots.createLots({
        vin: "1HGCM82633A004352",
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
      return lot
    }

    describe("wallet ledger", () => {
      it("credits a deposit and writes exactly one ledger row", async () => {
        await wallet.ensureWallet("user-1")
        const res = await wallet.deposit("user-1", money(1000_00), { id: "pay_1" })

        expect(res.available).toBe(1000_00)
        expect(res.held).toBe(0)

        const entries = await wallet.listLedgerEntries({ user_id: "user-1" })
        expect(entries).toHaveLength(1)
        expect(entries[0].type).toBe("DEPOSIT")
        expect(entries[0].amount).toBe(1000_00)
        expect(entries[0].balance_after).toBe(1000_00)
      })

      it("moves money between buckets on hold without changing the total", async () => {
        await wallet.ensureWallet("user-2")
        await wallet.deposit("user-2", money(1000_00))
        const res = await wallet.hold("user-2", money(400_00), { id: "bid_1" })

        expect(res.available).toBe(600_00)
        expect(res.held).toBe(400_00)
        expect(res.available + res.held).toBe(1000_00)
      })

      it("refuses to hold more than the buyer has", async () => {
        await wallet.ensureWallet("user-3")
        await wallet.deposit("user-3", money(100_00))

        await expect(
          wallet.hold("user-3", money(500_00), { id: "bid_x" })
        ).rejects.toThrow(/Insufficient funds/)
      })

      it("releases a hold back to available", async () => {
        await wallet.ensureWallet("user-4")
        await wallet.deposit("user-4", money(1000_00))
        await wallet.hold("user-4", money(400_00), { id: "bid_1" })
        const res = await wallet.release("user-4", money(400_00), { id: "outbid" })

        expect(res.available).toBe(1000_00)
        expect(res.held).toBe(0)
      })

      it("captures held funds permanently", async () => {
        await wallet.ensureWallet("user-5")
        await wallet.deposit("user-5", money(1000_00))
        await wallet.hold("user-5", money(400_00), { id: "bid_1" })
        const res = await wallet.capture("user-5", money(400_00), { id: "settle" })

        expect(res.available).toBe(600_00)
        expect(res.held).toBe(0)
        // 1000 deposited, 400 captured, 600 still ours
        expect(res.available + res.held).toBe(600_00)
      })

      it("reconciles wallet balances against the ledger", async () => {
        await wallet.ensureWallet("user-6")
        await wallet.deposit("user-6", money(1000_00))
        await wallet.hold("user-6", money(300_00), { id: "b1" })
        await wallet.hold("user-6", money(200_00), { id: "b2" })
        await wallet.release("user-6", money(200_00), { id: "b2-out" })
        await wallet.capture("user-6", money(300_00), { id: "win" })

        const result = await wallet.reconcile("user-6")
        expect(result.ok).toBe(true)
        expect(result.ledger.available).toBe(result.wallet.available)
        expect(result.ledger.held).toBe(result.wallet.held)
      })

      it("rejects a deposit that would create a fractional cent", async () => {
        await wallet.ensureWallet("user-7")
        await expect(
          wallet.deposit("user-7", { amount: 10.5, currency_code: "usd" })
        ).rejects.toThrow(/positive integer/)
      })
    })

    describe("bid placement", () => {
      it("accepts the first bid at the starting price", async () => {
        const lot = await makeLot()
        await lots.openLot(lot.id)

        const res = await lots.placeBid({
          lotId: lot.id,
          bidderId: "alice",
          bidderEmail: "alice@example.com",
          amount: 100_00,
        })

        expect(res.bid.amount).toBe(100_00)
        expect(res.lot.current_bid).toBe(100_00)
        expect(res.lot.highest_bidder_id).toBe("alice")
        expect(res.lot.bid_count).toBe(1)
      })

      it("rejects a bid below the increment", async () => {
        const lot = await makeLot()
        await lots.openLot(lot.id)
        await lots.placeBid({
          lotId: lot.id, bidderId: "alice", bidderEmail: "a@e.com", amount: 100_00,
        })

        await expect(
          lots.placeBid({
            lotId: lot.id, bidderId: "bob", bidderEmail: "b@e.com", amount: 110_00,
          })
        ).rejects.toThrow(/at least 12500/)
      })

      it("rejects a bid from the current highest bidder", async () => {
        const lot = await makeLot()
        await lots.openLot(lot.id)
        await lots.placeBid({
          lotId: lot.id, bidderId: "alice", bidderEmail: "a@e.com", amount: 100_00,
        })

        await expect(
          lots.placeBid({
            lotId: lot.id, bidderId: "alice", bidderEmail: "a@e.com", amount: 200_00,
          })
        ).rejects.toThrow(/already the highest/)
      })

      it("demotes the previous leader when outbid", async () => {
        const lot = await makeLot()
        await lots.openLot(lot.id)
        await lots.placeBid({
          lotId: lot.id, bidderId: "alice", bidderEmail: "a@e.com", amount: 100_00,
        })
        await lots.placeBid({
          lotId: lot.id, bidderId: "bob", bidderEmail: "b@e.com", amount: 125_00,
        })

        const bids = await lots.listBids({ lot_id: lot.id }, { order: { created_at: "ASC" } })
        const alice = bids.find((b) => b.bidder_id === "alice")
        const bob = bids.find((b) => b.bidder_id === "bob")
        expect(alice.status).toBe("OUTBID")
        expect(bob.status).toBe("ACCEPTED")
      })

      it("is idempotent when the same key is replayed", async () => {
        const lot = await makeLot()
        await lots.openLot(lot.id)

        const first = await lots.placeBid({
          lotId: lot.id, bidderId: "alice", bidderEmail: "a@e.com",
          amount: 100_00, idempotencyKey: "retry-me",
        })
        const second = await lots.placeBid({
          lotId: lot.id, bidderId: "alice", bidderEmail: "a@e.com",
          amount: 100_00, idempotencyKey: "retry-me",
        })

        expect(second.bid.id).toBe(first.bid.id)
        const bids = await lots.listBids({ lot_id: lot.id })
        expect(bids).toHaveLength(1)
      })

      it("rejects a bid on a closed auction", async () => {
        const lot = await makeLot({ ends_at: new Date(Date.now() - 1000) })
        await lots.openLot(lot.id)

        await expect(
          lots.placeBid({
            lotId: lot.id, bidderId: "alice", bidderEmail: "a@e.com", amount: 100_00,
          })
        ).rejects.toThrow(/ended/i)
      })
    })

    describe("anti-snipe", () => {
      it("extends the close time when bidding inside the final window", async () => {
        const lot = await makeLot({ ends_at: new Date(Date.now() + 60_000) })
        await lots.openLot(lot.id)

        const before = new Date(lot.ends_at).getTime()
        const res = await lots.placeBid({
          lotId: lot.id, bidderId: "alice", bidderEmail: "a@e.com", amount: 100_00,
        })

        expect(res.extension).toBe(true)
        expect(res.bid.caused_extension).toBe(true)
        expect(new Date(res.lot.ends_at).getTime()).toBeGreaterThan(before)
        expect(res.lot.extensions).toBe(1)
      })

      it("does not extend when bidding outside the final window", async () => {
        const lot = await makeLot({ ends_at: new Date(Date.now() + 3600_000) })
        await lots.openLot(lot.id)

        const res = await lots.placeBid({
          lotId: lot.id, bidderId: "alice", bidderEmail: "a@e.com", amount: 100_00,
        })

        expect(res.extension).toBe(false)
        expect(res.lot.extensions).toBe(0)
      })
    })

    describe("finalization", () => {
      it("marks SOLD and captures the winner's hold when reserve is met", async () => {
        const lot = await makeLot({ ends_at: new Date(Date.now() - 1000) })
        await lots.openLot(lot.id)

        await wallet.ensureWallet("winner")
        await wallet.deposit("winner", money(1000_00))
        await wallet.hold("winner", money(500_00), { id: "bid_1" })

        await lots.placeBid({
          lotId: lot.id, bidderId: "winner", bidderEmail: "w@e.com", amount: 500_00,
        })

        const res = await lots.finalizeLot(lot.id, wallet)
        expect(res.status).toBe("SOLD")
        expect(res.winnerId).toBe("winner")

        const settled = await wallet.reconcile("winner")
        expect(settled.wallet.held).toBe(0)
        expect(settled.wallet.available).toBe(500_00)
      })

      it("marks RESERVE_NOT_MET when the top bid is under reserve", async () => {
        const lot = await makeLot({
          ends_at: new Date(Date.now() - 1000),
          reserve_price: 900_00,
        })
        await lots.openLot(lot.id)
        await lots.placeBid({
          lotId: lot.id, bidderId: "alice", bidderEmail: "a@e.com", amount: 100_00,
        })

        const res = await lots.finalizeLot(lot.id, wallet)
        expect(res.status).toBe("RESERVE_NOT_MET")
        expect(res.winnerId).toBeNull()
      })

      it("is safe to run twice", async () => {
        const lot = await makeLot({ ends_at: new Date(Date.now() - 1000) })
        await lots.openLot(lot.id)
        await lots.placeBid({
          lotId: lot.id, bidderId: "alice", bidderEmail: "a@e.com", amount: 600_00,
        })

        const first = await lots.finalizeLot(lot.id, wallet)
        const second = await lots.finalizeLot(lot.id, wallet)
        expect(first.status).toBe("SOLD")
        expect(second.status).toBe("SOLD")
      })

      it("finds expired live lots for the cron job", async () => {
        const expired = await makeLot({ ends_at: new Date(Date.now() - 1000) })
        await lots.openLot(expired.id)
        const live = await makeLot({ ends_at: new Date(Date.now() + 3600_000) })
        await lots.openLot(live.id)

        const ids = await lots.findExpiredLiveLots()
        expect(ids).toContain(expired.id)
        expect(ids).not.toContain(live.id)
      })
    })

    describe("concurrency", () => {
      it("never lets two buyers overdraw one wallet", async () => {
        await wallet.ensureWallet("spender")
        await wallet.deposit("spender", money(100_00))

        // Six simultaneous attempts to hold 50.00 against a 100.00 balance.
        // Exactly two must succeed.
        const attempts = Array.from({ length: 6 }, (_, i) =>
          wallet
            .hold("spender", money(50_00), { id: `race-${i}` })
            .then(() => "ok")
            .catch(() => "rejected")
        )
        const results = await Promise.all(attempts)

        expect(results.filter((r) => r === "ok")).toHaveLength(2)

        const [w] = await wallet.listWallets({ user_id: "spender" })
        expect(w.balance_available).toBe(0)
        expect(w.balance_held).toBe(100_00)

        // And the ledger still reconciles to the balances.
        const check = await wallet.reconcile("spender")
        expect(check.ok).toBe(true)
      })

      it("keeps every bid when many buyers hammer the same lot", async () => {
        const lot = await makeLot()
        await lots.openLot(lot.id)

        // Ten different buyers, all bidding valid increasing amounts at once.
        const amounts = Array.from({ length: 10 }, (_, i) => 100_00 + i * 25_00)
        const attempts = amounts.map((amount, i) =>
          lots
            .placeBid({
              lotId: lot.id,
              bidderId: `buyer-${i}`,
              bidderEmail: `b${i}@e.com`,
              amount,
            })
            .then(() => "ok")
            .catch(() => "rejected")
        )
        const results = await Promise.all(attempts)

        // Some may legitimately lose the race for a given price, but the lot
        // must never end up with a current_bid no bid row accounts for.
        const final = await lots.retrieveLot(lot.id)
        const bids = await lots.listBids({ lot_id: lot.id })

        const highest = Math.max(...bids.map((b) => b.amount))
        expect(final.current_bid).toBe(highest)
        expect(final.bid_count).toBe(bids.length)
        expect(final.current_bid).toBeGreaterThanOrEqual(100_00)

        // The winning bidder is whoever actually holds the top bid.
        const winning = bids.find((b) => b.amount === final.current_bid)!
        expect(final.highest_bidder_id).toBe(winning.bidder_id)

        // No two accepted bids share the top amount.
        const accepted = bids.filter((b) => b.status === "ACCEPTED")
        expect(accepted).toHaveLength(1)
        expect(accepted[0].amount).toBe(final.current_bid)
      })
    })
  },
})
