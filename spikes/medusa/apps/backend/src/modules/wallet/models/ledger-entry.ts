import { model } from "@medusajs/framework/utils"

/**
 * Append-only ledger. One row per balance movement, never updated, never
 * deleted. This table is the source of truth: `wallet.balance_*` columns are a
 * running cache of it that can always be rebuilt by replaying entries in
 * created_at order.
 *
 * Every row carries the resulting balance so any single row can be checked
 * against its predecessor without replaying the whole history.
 */
const LedgerEntry = model.define("ledger_entry", {
  id: model.id({ prefix: "led" }).primaryKey(),

  wallet_id: model.text(),
  user_id: model.text(),
  currency_code: model.text(),

  // DEPOSIT | HOLD | RELEASE | CAPTURE | WITHDRAWAL | ADJUSTMENT
  type: model.text(),

  // Signed delta applied to the TOTAL balance (available + held).
  // A HOLD is +amount; a RELEASE is -amount. This keeps total reconciliation
  // trivial: sum(amount) == balance_available + balance_held.
  amount: model.number(),

  // Running total after this entry was applied, for spot checks.
  balance_after: model.number(),

  // What caused it: "bid", "auction_settle", "payment", "admin"
  reference_type: model.text().nullable(),
  reference_id: model.text().nullable(),

  note: model.text().nullable(),
})

export default LedgerEntry
