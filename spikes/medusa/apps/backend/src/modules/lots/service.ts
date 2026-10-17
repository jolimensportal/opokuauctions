import { MedusaService, MedusaError } from "@medusajs/framework/utils"
import { Lot, Bid } from "./models"

/**
 * TRANSACTIONS — READ THIS BEFORE TOUCHING ANY MONEY PATH.
 *
 * `this.baseRepository_.transaction(cb)` looks like it opens a transaction and
 * does NOT. Verified on Medusa 2.21.2: `txid_current_if_assigned()` returns null
 * inside the callback, and three concurrent `SELECT ... FOR UPDATE` on one row
 * all read the same value and all wrote it back, producing silent lost updates.
 * For a ledger that fails silently rather than loudly, which is the worst way
 * to fail.
 *
 * The real transaction is knex's: `this.knex.transaction(async (trx) => ...)`.
 * `trx` is itself a query builder, so row locks (`forUpdate()`) apply inside it.
 * Every mutation below runs through that, never through baseRepository_.
 */

/** Bid inside this window pushes the close time out instead of ending. */
const ANTI_SNIPE_WINDOW_MS = 2 * 60 * 1000
const ANTI_SNIPE_EXTENSION_MS = 2 * 60 * 1000

class LotsModuleService extends MedusaService({ Lot, Bid }) {
  private knexFor(manager: any) {
    return manager.getConnection().getKnex()
  }

  private get knex() {
    return this.knexFor(this.baseRepository_.manager_)
  }

  /** Lock and read a lot. The lock is the whole ballgame for bid safety. */
  private async lockLot(knex: any, lotId: string) {
    const rows = await knex("lot")
      .where({ id: lotId })
      .whereNull("deleted_at")
      .forUpdate()
      .select("*")

    if (!rows.length) {
      throw new MedusaError(`Lot ${lotId} not found`, "LOT_NOT_FOUND")
    }
    return rows[0]
  }

  /** Open a lot for bidding: UPCOMING -> LIVE. */
  async openLot(lotId: string): Promise<any> {
    return await this.knex.transaction(async (knex: any) => {
      const lot = await this.lockLot(knex, lotId)

      if (lot.status !== "UPCOMING") {
        throw new MedusaError(
          `Lot ${lotId} is ${lot.status}, expected UPCOMING`,
          "LOT_NOT_OPENABLE"
        )
      }
      if (new Date(lot.starts_at) > new Date()) {
        throw new MedusaError(`Lot ${lotId} has not started yet`, "LOT_NOT_STARTED")
      }

      await knex("lot").where({ id: lotId }).update({
        status: "LIVE",
        updated_at: new Date(),
      })
    })
  }

  /**
   * Place a bid.
   *
   * Correctness rules, in order:
   *  1. Lock the lot row FOR UPDATE. Without it, two simultaneous bids can both
   *     read current_bid and both write current_bid + increment, so the loser
   *     silently disappears.
   *  2. Re-read the lot inside the lock. Never trust a value read before it.
   *  3. Refuse if the lot is not LIVE, if it has closed, or if the amount does
   *     not beat current_bid by at least bid_increment.
   *  4. Idempotency: a replayed key returns the original bid. The unique index
   *     on idempotency_key is the real guarantee; the pre-check is just fast.
   *  5. Anti-snipe: a bid landing inside the final window extends the close.
   *
   * Money is NOT touched here. Holding funds is the wallet module's job, in a
   * separate transaction, so a payment failure cannot leave a bid accepted
   * against an unfunded bidder.
   */
  async placeBid(params: {
    lotId: string
    bidderId: string
    bidderEmail: string
    amount: number
    idempotencyKey?: string
  }): Promise<{
    bid: any
    lot: any
    previousBidderId: string | null
    extension: boolean
  }> {
    const { lotId, bidderId, bidderEmail, amount, idempotencyKey } = params

    if (!Number.isInteger(amount) || amount <= 0) {
      throw new MedusaError("Bid must be a positive integer", "INVALID_BID")
    }

    return await this.knex.transaction(async (knex: any) => {

      // Idempotency: a retry must not double-bid.
      if (idempotencyKey) {
        const prior = await knex("bid")
          .where({ idempotency_key: idempotencyKey })
          .whereNull("deleted_at")
          .select("id")
          .first()

        if (prior) {
          const [bid] = await this.listBids({ id: prior.id }, { take: 1 })
          return {
            bid,
            lot: await this.retrieveLot(lotId),
            previousBidderId: null,
            extension: false,
          }
        }
      }

      const lot = await this.lockLot(knex, lotId)
      const now = new Date()

      if (lot.status !== "LIVE") {
        throw new MedusaError(
          `Lot is ${lot.status}, not accepting bids`,
          "LOT_NOT_LIVE"
        )
      }
      if (new Date(lot.ends_at) <= now) {
        throw new MedusaError("Auction has ended", "AUCTION_ENDED")
      }
      if (lot.highest_bidder_id === bidderId) {
        throw new MedusaError(
          "You are already the highest bidder",
          "ALREADY_HIGHEST"
        )
      }

      const minimum =
        lot.bid_count === 0 ? lot.starting_bid : lot.current_bid + lot.bid_increment
      if (amount < minimum) {
        throw new MedusaError(
          `Bid must be at least ${minimum} (current ${lot.current_bid}, increment ${lot.bid_increment})`,
          "BID_TOO_LOW"
        )
      }

      // Anti-snipe: inside the window, push the clock out.
      const msRemaining = new Date(lot.ends_at).getTime() - now.getTime()
      const shouldExtend = msRemaining <= ANTI_SNIPE_WINDOW_MS
      const newEndsAt = shouldExtend
        ? new Date(now.getTime() + ANTI_SNIPE_EXTENSION_MS)
        : new Date(lot.ends_at)

      const bidId = `bid_${Math.random().toString(36).slice(2, 12)}`

      await knex("bid").insert({
        id: bidId,
        lot_id: lotId,
        bidder_id: bidderId,
        bidder_email: bidderEmail,
        amount,
        currency_code: lot.currency_code,
        status: "ACCEPTED",
        caused_extension: shouldExtend,
        idempotency_key: idempotencyKey ?? null,
      })

      // Demote the previous leader. Bids stay in the table; only status moves.
      if (lot.highest_bidder_id) {
        await knex("bid")
          .where({ lot_id: lotId, bidder_id: lot.highest_bidder_id, status: "ACCEPTED" })
          .whereNull("deleted_at")
          .update({ status: "OUTBID", updated_at: new Date() })
      }

      await knex("lot").where({ id: lotId }).update({
        current_bid: amount,
        highest_bidder_id: bidderId,
        bid_count: knex.raw("bid_count + 1"),
        ends_at: newEndsAt,
        extensions: knex.raw("extensions + ?", [shouldExtend ? 1 : 0]),
        updated_at: now,
      })

      // Read back through the SAME transaction handle. Going through the ORM
      // here would use a different connection and return the pre-update row,
      // which reads as "the bid did not stick".
      const [freshLot] = await knex("lot").where({ id: lotId }).select("*")

      return {
        bid: {
          id: bidId,
          amount,
          bidder_id: bidderId,
          caused_extension: shouldExtend,
        },
        lot: freshLot,
        previousBidderId: lot.highest_bidder_id,
        extension: shouldExtend,
      }
    })
  }

  /**
   * Close an expired auction.
   *   reserve met -> SOLD
   *   otherwise   -> RESERVE_NOT_MET
   * Safe to call repeatedly: the status guard makes the second call a no-op.
   */
  async finalizeLot(lotId: string): Promise<{
    status: string
    winnerId: string | null
  }> {
    return await this.knex.transaction(async (knex: any) => {
      const lot = await this.lockLot(knex, lotId)

      if (lot.status !== "LIVE") {
        return { status: lot.status, winnerId: null }
      }
      if (new Date(lot.ends_at) > new Date()) {
        throw new MedusaError("Lot has not expired yet", "LOT_NOT_EXPIRED")
      }

      const sold = lot.bid_count > 0 && lot.current_bid >= lot.reserve_price
      const status = sold ? "SOLD" : "RESERVE_NOT_MET"

      await knex("lot").where({ id: lotId }).update({
        status,
        updated_at: new Date(),
      })

      if (sold) {
        const top = await knex("bid")
          .where({ lot_id: lotId })
          .whereNull("deleted_at")
          .orderBy("amount", "desc")
          .orderBy("created_at", "asc")
          .select("id")
          .first()
        if (top) {
          await knex("bid").where({ id: top.id }).update({
            status: "WINNING",
            updated_at: new Date(),
          })
        }
      }

      return { status, winnerId: sold ? lot.highest_bidder_id : null }
    })
  }

  /** Every live lot whose close time has passed. Used by the cron job. */
  async findExpiredLiveLots(limit = 100): Promise<string[]> {
    const rows = await this.knex("lot")
      .where({ status: "LIVE" })
      .whereNull("deleted_at")
      .where("ends_at", "<=", this.knex.fn.now())
      .orderBy("ends_at", "asc")
      .limit(limit)
      .select("id")

    return rows.map((r: any) => r.id)
  }
}

export default LotsModuleService
