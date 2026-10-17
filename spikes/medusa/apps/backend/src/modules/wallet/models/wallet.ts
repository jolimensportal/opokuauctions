import { model } from "@medusajs/framework/utils"

/**
 * A buyer's balance in a single currency, in minor units (cents).
 *
 * Two buckets, never one:
 *   balance_available - spendable right now
 *   balance_held      - committed to winning bids, not spendable
 *
 * Keeping them separate is what stops a buyer from placing two bids that
 * together exceed their funds. A bid only ever moves money held -> available
 * to held; it never creates or destroys value.
 */
const Wallet = model.define("wallet", {
  id: model.id({ prefix: "wallet" }).primaryKey(),

  user_id: model.text(),
  currency_code: model.text(),

  balance_available: model.number().default(0),
  balance_held: model.number().default(0),

  // Optimistic concurrency guard. Every mutation bumps this in the same
  // statement that changes a balance, so a lost update is detectable.
  version: model.number().default(1),
})

export default Wallet
