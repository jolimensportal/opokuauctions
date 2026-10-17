export default async function debugSql({ container }: { container: any }) {
  const wallet: any = container.resolve("wallet")
  const lots: any = container.resolve("lots")

  // Seed one wallet row so the queries have something to find.
  await wallet.ensureWallet("probe-user")
  await wallet.deposit("probe-user", { amount: 500, currency_code: "usd" })

  const manager = wallet.baseRepository_.manager_
  const conn = manager.getConnection()
  console.log("=== connection type ===")
  console.log("typeof conn:", typeof conn)
  console.log("is knex-like (callable):", typeof conn === "function")

  console.log("\n=== A) raw string + params array ===")
  try {
    const r = await conn.execute("select balance_available from wallet where user_id = $1", ["probe-user"])
    console.log("  OK:", JSON.stringify(r.rows ?? r))
  } catch (e: any) {
    console.log("  FAILED:", e?.message?.split("\n")[0])
  }

  console.log("\n=== B) knex query builder ===")
  try {
    const rows = await conn("wallet")
      .where({ user_id: "probe-user" })
      .whereNull("deleted_at")
      .select("id", "balance_available", "balance_held")
    console.log("  OK:", JSON.stringify(rows))
  } catch (e: any) {
    console.log("  FAILED:", e?.message?.split("\n")[0])
  }

  console.log("\n=== C) knex builder + forUpdate (row lock) ===")
  try {
    const rows = await conn("lot")
      .whereNull("deleted_at")
      .orderBy("created_at", "desc")
      .limit(1)
      .forUpdate()
      .select("id")
    console.log("  OK (no lot rows expected):", JSON.stringify(rows))
  } catch (e: any) {
    console.log("  FAILED:", e?.message?.split("\n")[0])
  }

  console.log("\n=== D) insert via knex, returning row ===")
  try {
    const [row] = await conn("lot")
      .insert({
        vin: "PROBE-1", year: 2020, make: "Toyota", model: "Corolla", city: "Detroit",
        photos: JSON.stringify([]), condition: "clean_title", currency_code: "usd",
        starting_bid: 100, reserve_price: 500, bid_increment: 25,
        starts_at: new Date(), ends_at: new Date(Date.now() + 3600_000),
        status: "UPCOMING", current_bid: 0, bid_count: 0, extensions: 0,
        id: "lot_probe_1",
      })
      .returning("*")
    console.log("  OK, status:", row?.status, "id:", row?.id)
  } catch (e: any) {
    console.log("  FAILED:", e?.message?.split("\n")[0])
  }

  process.exit(0)
}
