import { MedusaService, MedusaError } from "@medusajs/framework/utils"
import { Wallet, LedgerEntry } from "./models"

/** Ledger entry types. Held deliberately as strings so the DB cannot constrain us. */
export const LedgerType = {
  DEPOSIT: "DEPOSIT",
  HOLD: "HOLD",
  RELEASE: "RELEASE",
  CAPTURE: "CAPTURE",
  WITHDRAWAL: "WITHDRAWAL",
  ADJUSTMENT: "ADJUSTMENT",
} as const

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

type Money = {
  amount: number
  currency_code?: string
}

/**
 * Money service for the OpokuAuctions corridor.
 *
 * Every mutation follows the same three rules:
 *
 *  1. Lock the wallet row FOR UPDATE before reading a balance. Without this,
 *     two concurrent bids can both read the same balance and both succeed,
 *     which is a double-spend.
 *  2. Refuse to move money the buyer does not have. No negative balances, ever.
 *  3. Write the ledger row in the same transaction as the balance change. A
 *     balance without a matching ledger row is a bug, not a state.
 *
 * A note on the plumbing, because it cost real time to get right: in Medusa the
 * route to SQL is `baseRepository_.manager_.getConnection().getKnex()`. The
 * manager's own `execute(sql, params)` silently drops parameters, and a module
 * service's `__container__` is a scoped Awilix proxy whose `.resolve` throws.
 * Knex is the only accessor that binds reliably.
 */
class WalletModuleService extends MedusaService({
  Wallet,
  LedgerEntry,
}) {
  /** Knex for a given (optionally transactional) manager. */
  private knexFor(manager: any) {
    return manager.getConnection().getKnex()
  }

  private get knex() {
    return this.knexFor(this.baseRepository_.manager_)
  }

  /** Lock a wallet row for the rest of the current transaction. */
  private async lockWallet(
    knex: any,
    userId: string,
    currencyCode: string
  ): Promise<{ id: string; balance_available: number; balance_held: number }> {
    const rows = await knex("wallet")
      .where({ user_id: userId, currency_code: currencyCode })
      .whereNull("deleted_at")
      .forUpdate()
      .select("id", "balance_available", "balance_held")

    if (!rows.length) {
      throw new MedusaError(
        `No ${currencyCode.toUpperCase()} wallet for user ${userId}`,
        "WALLET_NOT_FOUND"
      )
    }
    return rows[0]
  }

  private async writeLedger(
    knex: any,
    entry: {
      wallet_id: string
      user_id: string
      currency_code: string
      type: string
      amount: number
      balance_after: number
      reference_type?: string | null
      reference_id?: string | null
      note?: string | null
    }
  ): Promise<void> {
    // Append-only: no update path exists on this table by design.
    // The id is generated here because a raw knex insert bypasses the model's
    // id generator, and the column is NOT NULL.
    await knex("ledger_entry").insert({
      id: `led_${Math.random().toString(36).slice(2, 12)}`,
      wallet_id: entry.wallet_id,
      user_id: entry.user_id,
      currency_code: entry.currency_code,
      type: entry.type,
      amount: entry.amount,
      balance_after: entry.balance_after,
      reference_type: entry.reference_type ?? null,
      reference_id: entry.reference_id ?? null,
      note: entry.note ?? null,
    })
  }

  private assertPositiveInteger(amount: number, verb: string) {
    if (!Number.isInteger(amount) || amount <= 0) {
      throw new MedusaError(`${verb} amount must be a positive integer`, "INVALID_AMOUNT")
    }
  }

  /** Get a buyer's wallet, creating it on first use. */
  async ensureWallet(userId: string, currencyCode = "usd"): Promise<any> {
    const existing = await this.listWallets(
      { user_id: userId, currency_code: currencyCode },
      { take: 1 }
    )
    if (existing[0]) {
      return existing[0]
    }
    return await this.createWallets({
      user_id: userId,
      currency_code: currencyCode,
      balance_available: 0,
      balance_held: 0,
      version: 1,
    })
  }

  /**
   * Add funds. The only operation that creates value, and only ever called
   * from a verified payment provider callback.
   */
  async deposit(
    userId: string,
    { amount, currency_code = "usd" }: Money,
    reference: { type?: string; id?: string; note?: string } = {}
  ): Promise<{ available: number; held: number }> {
    this.assertPositiveInteger(amount, "Deposit")

    return await this.knex.transaction(async (knex: any) => {
      const wallet = await this.lockWallet(knex, userId, currency_code)
      const next = wallet.balance_available + amount

      await knex("wallet").where({ id: wallet.id }).update({
        balance_available: next,
        version: knex.raw("version + 1"),
        updated_at: new Date(),
      })

      await this.writeLedger(knex, {
        wallet_id: wallet.id,
        user_id: userId,
        currency_code,
        type: LedgerType.DEPOSIT,
        amount,
        balance_after: next + wallet.balance_held,
        reference_type: reference.type ?? "payment",
        reference_id: reference.id ?? null,
        note: reference.note ?? null,
      })

      return { available: next, held: wallet.balance_held }
    })
  }

  /**
   * Commit funds to a bid: available -> held.
   * Throws if the buyer cannot cover the amount. This is the double-spend
   * gate, and it only works because the row is locked first.
   */
  async hold(
    userId: string,
    { amount, currency_code = "usd" }: Money,
    reference: { type?: string; id?: string; note?: string } = {}
  ): Promise<{ available: number; held: number }> {
    this.assertPositiveInteger(amount, "Hold")

    return await this.knex.transaction(async (knex: any) => {
      const wallet = await this.lockWallet(knex, userId, currency_code)

      if (wallet.balance_available < amount) {
        throw new MedusaError(
          `Insufficient funds: need ${amount}, available ${wallet.balance_available}`,
          "INSUFFICIENT_FUNDS"
        )
      }

      const nextAvailable = wallet.balance_available - amount
      const nextHeld = wallet.balance_held + amount

      await knex("wallet").where({ id: wallet.id }).update({
        balance_available: nextAvailable,
        balance_held: nextHeld,
        version: knex.raw("version + 1"),
        updated_at: new Date(),
      })

      // A HOLD increases the TOTAL balance (funds move bucket, not out of the
      // account), so the ledger amount is positive.
      await this.writeLedger(knex, {
        wallet_id: wallet.id,
        user_id: userId,
        currency_code,
        type: LedgerType.HOLD,
        amount,
        balance_after: nextAvailable + nextHeld,
        reference_type: reference.type ?? "bid",
        reference_id: reference.id ?? null,
        note: reference.note ?? null,
      })

      return { available: nextAvailable, held: nextHeld }
    })
  }

  /** Release a hold back to available: held -> available. Losing bidder path. */
  async release(
    userId: string,
    { amount, currency_code = "usd" }: Money,
    reference: { type?: string; id?: string; note?: string } = {}
  ): Promise<{ available: number; held: number }> {
    this.assertPositiveInteger(amount, "Release")

    return await this.knex.transaction(async (knex: any) => {
      const wallet = await this.lockWallet(knex, userId, currency_code)

      if (wallet.balance_held < amount) {
        throw new MedusaError(
          `Cannot release ${amount}: only ${wallet.balance_held} held`,
          "INSUFFICIENT_HOLD"
        )
      }

      const nextAvailable = wallet.balance_available + amount
      const nextHeld = wallet.balance_held - amount

      await knex("wallet").where({ id: wallet.id }).update({
        balance_available: nextAvailable,
        balance_held: nextHeld,
        version: knex.raw("version + 1"),
        updated_at: new Date(),
      })

      await this.writeLedger(knex, {
        wallet_id: wallet.id,
        user_id: userId,
        currency_code,
        type: LedgerType.RELEASE,
        amount: -amount,
        balance_after: nextAvailable + nextHeld,
        reference_type: reference.type ?? "bid",
        reference_id: reference.id ?? null,
        note: reference.note ?? null,
      })

      return { available: nextAvailable, held: nextHeld }
    })
  }

  /** Take held funds permanently. Winning bidder pays path. */
  async capture(
    userId: string,
    { amount, currency_code = "usd" }: Money,
    reference: { type?: string; id?: string; note?: string } = {}
  ): Promise<{ available: number; held: number }> {
    this.assertPositiveInteger(amount, "Capture")

    return await this.knex.transaction(async (knex: any) => {
      const wallet = await this.lockWallet(knex, userId, currency_code)

      if (wallet.balance_held < amount) {
        throw new MedusaError(
          `Cannot capture ${amount}: only ${wallet.balance_held} held`,
          "INSUFFICIENT_HOLD"
        )
      }

      const nextHeld = wallet.balance_held - amount

      await knex("wallet").where({ id: wallet.id }).update({
        balance_held: nextHeld,
        version: knex.raw("version + 1"),
        updated_at: new Date(),
      })

      await this.writeLedger(knex, {
        wallet_id: wallet.id,
        user_id: userId,
        currency_code,
        type: LedgerType.CAPTURE,
        amount: -amount,
        balance_after: wallet.balance_available + nextHeld,
        reference_type: reference.type ?? "auction_settle",
        reference_id: reference.id ?? null,
        note: reference.note ?? null,
      })

      return { available: wallet.balance_available, held: nextHeld }
    })
  }

  /**
   * Verify a wallet against its own ledger. Catches any drift between the
   * cached balance columns and the immutable record of what actually happened.
   */
  async reconcile(
    userId: string,
    currencyCode = "usd"
  ): Promise<{
    wallet: { available: number; held: number }
    ledger: { available: number; held: number }
    ok: boolean
  }> {
    const [wallet] = await this.listWallets(
      { user_id: userId, currency_code: currencyCode },
      { take: 1 }
    )
    if (!wallet) {
      throw new MedusaError("Wallet not found", "WALLET_NOT_FOUND")
    }

    const entries = await this.listLedgerEntries(
      { wallet_id: wallet.id },
      { order: { created_at: "ASC" } }
    )

    // Two independent invariants, because money moves in two ways.
    //
    // `held` counts only bucket movement: a HOLD adds to held, a RELEASE or
    // CAPTURE removes from it. Neither changes how much money the buyer owns.
    //
    // `total` counts only what the buyer actually owns. A DEPOSIT adds to it; a
    // CAPTURE (money paid out) subtracts. A HOLD or RELEASE is neutral, because
    // those move money between available and held rather than in or out.
    //
    // Getting this wrong is easy and it is what the first version of this
    // function did: summing every signed amount counted HOLDs as new money and
    // the wallet appeared to have drifted when it had not.
    let held = 0
    let total = 0
    for (const e of entries) {
      if (e.type === LedgerType.HOLD) {
        held += Math.abs(e.amount)
      } else if (e.type === LedgerType.RELEASE) {
        held -= Math.abs(e.amount)
      } else if (e.type === LedgerType.CAPTURE) {
        held -= Math.abs(e.amount)
        total -= Math.abs(e.amount)
      } else if (e.type === LedgerType.DEPOSIT) {
        total += Math.abs(e.amount)
      } else if (e.type === LedgerType.WITHDRAWAL) {
        total -= Math.abs(e.amount)
      } else if (e.type === LedgerType.ADJUSTMENT) {
        total += e.amount
      }
    }

    const walletTotal = wallet.balance_available + wallet.balance_held

    return {
      wallet: { available: wallet.balance_available, held: wallet.balance_held },
      ledger: { available: total - held, held },
      ok: total === walletTotal && held === wallet.balance_held,
    }
  }
}

export default WalletModuleService
