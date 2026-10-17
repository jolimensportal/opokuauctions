import { model } from "@medusajs/framework/utils"

/**
 * A vehicle offered at auction on the OpokuAuctions corridor.
 *
 * Status lifecycle:
 *   UPCOMING -> LIVE -> SOLD            (reserve met, winner exists)
 *                 -> RESERVE_NOT_MET    (closed with no qualifying bid)
 *
 * Money is stored in integer minor units (cents) throughout. Never floats.
 */
const Lot = model.define("lot", {
  id: model.id({ prefix: "lot" }).primaryKey(),

  // Vehicle identity
  vin: model.text().nullable(),
  year: model.number(),
  make: model.text(),
  model: model.text(),
  trim: model.text().nullable(),
  city: model.text(),
  photos: model.json().default([]),

  // Condition / provenance
  condition: model.text().default("unknown"), // salvage | clean_title | project
  odometer: model.number().nullable(),
  damage_primary: model.text().nullable(),

  // Auction terms (all money in minor units)
  currency_code: model.text().default("usd"),
  starting_bid: model.number(),
  reserve_price: model.number().default(0),
  bid_increment: model.number(),

  // Timing
  starts_at: model.dateTime(),
  ends_at: model.dateTime(),

  // Live state
  status: model
    .enum(["UPCOMING", "LIVE", "ENDED", "SOLD", "RESERVE_NOT_MET", "CANCELLED"])
    .default("UPCOMING"),
  current_bid: model.number().default(0),
  highest_bidder_id: model.text().nullable(),
  bid_count: model.number().default(0),

  // Anti-snipe: how many times the clock has been pushed back
  extensions: model.number().default(0),
})
export default Lot
