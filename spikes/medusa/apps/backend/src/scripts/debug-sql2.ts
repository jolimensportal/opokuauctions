export default async function debugSql2({ container }: { container: any }) {
  const wallet: any = container.resolve("wallet")
  const manager = wallet.baseRepository_.manager_
  const conn = manager.getConnection()

  console.log("=== connection type ===")
  console.log("typeof conn:", typeof conn, "| knex-like:", typeof conn === "function")

  console.log("\n=== A) knex insert wallet, returning * ===")
  try {
    const [row] = await conn("wallet")
      .insert({
        user_id: "probe-user", currency_code: "usd",
        balance_available: 500, balance_held: 0, version: 1,
        id: "wallet_probe_1",
      })
      .returning("*")
    console.log("  OK available:", row?.balance_available)
  } catch (e: any) {
    console.log("  FAILED:", e?.message?.split("\n")[0])
  }

  console.log("\n=== B) knex select where ===")
  try {
    const rows = await conn("wallet")
      .where({ user_id: "probe-user" })
      .whereNull("deleted_at")
      .select("id", "balance_available", "balance_held")
    console.log("  OK:", JSON.stringify(rows))
  } catch (e: any) {
    console.log("  FAILED:", e?.message?.split("\n")[0])
  }

  console.log("\n=== C) raw string + params array ===")
  try {
    const r = await conn.execute("select balance_available from wallet where user_id = $1", ["probe-user"])
    console.log("  OK:", JSON.stringify(r.rows ?? r))
  } catch (e: any) {
    console.log("  FAILED:", e?.message?.split("\n")[0])
  }

  console.log("\n=== D) knex insert lot + forUpdate select ===")
  try {
    const [row] = await conn("lot")
      .insert({
        id: "lot_probe_1", vin: "PROBE-1", year: 2020, make: "Toyota", model: "Corolla",
        city: "Detroit", photos: JSON.stringify([]), condition: "clean_title",
        currency_code: "usd", starting_bid: 100, reserve_price: 500, bid_increment: 25,
        starts_at: new Date(), ends_at: new Date(Date.now() + 3600_000),
        status: "UPCOMING", current_bid: 0, bid_count: 0, extensions: 0,
      })
      .returning("*")
    console.log("  insert OK status:", row?.status)

    const locked = await conn("lot")
      .where({ id: "lot_probe_1" })
      .whereNull("deleted_at")
      .forUpdate()
      .select("id", "current_bid")
    console.log("  forUpdate OK:", JSON.stringify(locked))
  } catch (e: any) {
    console.log("  FAILED:", e?.message?.split("\n")[0])
  }

  console.log("\n=== E) knex update ===")
  try {
    await conn("wallet")
      .where({ id: "wallet_probe_1" })
      .update({ balance_available: 250, version: 2, updated_at: new Date() })
    const rows = await conn("wallet").where({ id: "wallet_probe_1" }).select("balance_available", "version")
    console.log("  OK:", JSON.stringify(rows))
  } catch (e: any) {
    console.log("  FAILED:", e?.message?.split("\n")[0])
  }

  process.exit(0)
}
