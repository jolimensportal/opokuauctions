import { model } from "@medusajs/framework/utils"

/**
 * A single bid placed on a lot. Bids are immutable once written: corrections
 * happen by voiding, never by updating an amount. That keeps the audit trail
 * honest and makes replay safe.
 */
const Bid = model.define("bid", {
  id: model.id({ prefix: "bid" }).primaryKey(),

  lot_id: model.text(),
  bidder_id: model.text(),
  bidder_email: model.text(),

  // minor units
  amount: model.number(),
  currency_code: model.text().default("usd"),

  // outcome
  status: model.enum(["ACCEPTED", "OUTBID", "WINNING", "RETRACTED"]).default("ACCEPTED"),

  // Did this bid trigger an anti-snipe extension?
  caused_extension: model.boolean().default(false),

  // Idempotency key supplied by the client so a retry cannot double-bid
  idempotency_key: model.text().nullable(),
})

export default Bid
