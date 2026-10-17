export default async function debugSql3({ container }: { container: any }) {
  const wallet: any = container.resolve("wallet")
  const manager = wallet.baseRepository_.manager_
  const conn = manager.getConnection()

  console.log("=== accessor surface ===")
  console.log("conn methods:", Object.getOwnPropertyNames(Object.getPrototypeOf(conn)).join(", "))
  console.log("conn.getKnex:", typeof (conn as any).getKnex)
  console.log("manager.execute:", typeof (manager as any).execute)
  console.log("manager.getConnection:", typeof (manager as any).getConnection)
  console.log("manager.transaction:", typeof (manager as any).transaction)
  console.log("manager.getRepository:", typeof (manager as any).getRepository)

  // Seed via knex if available, else via manager.execute
  const knex = (conn as any).getKnex?.()
  console.log("\nknex typeof:", typeof knex)

  if (knex) {
    console.log("\n=== knex insert wallet ===")
    try {
      const [row] = await knex("wallet")
        .insert({
          user_id: "probe-user", currency_code: "usd",
          balance_available: 500, balance_held: 0, version: 1, id: "wallet_probe_1",
        })
        .returning("*")
      console.log("  OK available:", row?.balance_available)
    } catch (e: any) {
      console.log("  FAILED:", e?.message?.split("\n")[0])
    }

    console.log("\n=== knex forUpdate select ===")
    try {
      const rows = await knex("wallet")
        .where({ user_id: "probe-user" })
        .whereNull("deleted_at")
        .forUpdate()
        .select("id", "balance_available", "balance_held")
      console.log("  OK:", JSON.stringify(rows))
    } catch (e: any) {
      console.log("  FAILED:", e?.message?.split("\n")[0])
    }
  }

  console.log("\n=== manager.execute with params ===")
  try {
    const r = await (manager as any).execute("select balance_available from wallet where user_id = ?", ["probe-user"])
    console.log("  OK (?):", JSON.stringify(r.rows ?? r))
  } catch (e: any) {
    console.log("  FAILED (?):", e?.message?.split("\n")[0])
  }

  console.log("\n=== conn.execute with bindings object ===")
  try {
    const r = await (conn as any).execute("select balance_available from wallet where user_id = :u", { u: "probe-user" })
    console.log("  OK (:named):", JSON.stringify(r.rows ?? r))
  } catch (e: any) {
    console.log("  FAILED (:named):", e?.message?.split("\n")[0])
  }

  process.exit(0)
}
