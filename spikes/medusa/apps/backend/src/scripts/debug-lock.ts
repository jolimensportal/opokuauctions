export default async function debugLock({ container }: { container: any }) {
  const wallet: any = container.resolve("wallet")
  const repo = wallet.baseRepository_

  console.log("=== transaction wrapper implementation ===")
  console.log("repo.transaction source:", repo.transaction.toString().slice(0, 300))

  const manager = repo.manager_
  console.log("\nmanager methods:", Object.getOwnPropertyNames(Object.getPrototypeOf(manager)).slice(0, 25).join(", "))
  console.log("manager.getTransactionManager:", typeof (manager as any).getTransactionManager)
  console.log("manager.getEntityManager:", typeof (manager as any).getEntityManager)
  console.log("manager.fork:", typeof (manager as any).fork)

  // Seed a counter row we can race on.
  const knex = manager.getConnection().getKnex()
  await knex("wallet").insert({
    user_id: "lock-probe", currency_code: "usd",
    balance_available: 0, balance_held: 0, version: 1, id: "wallet_lock_probe",
  }).onConflict("id").ignore()

  console.log("\n=== PROBE 1: do concurrent FOR UPDATE locks serialize? ===")
  const order: string[] = []
  const racer = (tag: string) =>
    repo.transaction(async (tx: any) => {
      const k = tx.getConnection().getKnex()
      const rows = await k("wallet")
        .where({ id: "wallet_lock_probe" })
        .whereNull("deleted_at")
        .forUpdate()
        .select("balance_available")
      order.push(`${tag}:read=${rows[0]?.balance_available}`)
      const next = (rows[0]?.balance_available ?? 0) + 1
      await new Promise((r) => setTimeout(r, 120)) // widen the race window
      await k("wallet").where({ id: "wallet_lock_probe" }).update({ balance_available: next })
      order.push(`${tag}:wrote=${next}`)
      return next
    })

  const results = await Promise.all([racer("A"), racer("B"), racer("C")])
  const [finalRow] = await knex("wallet").where({ id: "wallet_lock_probe" }).select("balance_available")
  console.log("  interleaving:", order.join(" | "))
  console.log("  returned:", results.join(", "))
  console.log("  final stored:", finalRow?.balance_available)
  console.log(
    finalRow?.balance_available === 3
      ? "  VERDICT: locks SERIALIZE correctly (3 increments preserved)"
      : "  VERDICT: LOST UPDATES — row lock is NOT holding"
  )

  // Is the transaction real? Check for a live transaction on the connection.
  console.log("\n=== PROBE 2: is a real DB transaction open inside the wrapper? ===")
  await repo.transaction(async (tx: any) => {
    const k = tx.getConnection().getKnex()
    const t = await k.raw("select txid_current_if_assigned() as txid")
    console.log("  txid inside transaction:", JSON.stringify(t.rows ?? t))
  })

  process.exit(0)
}
