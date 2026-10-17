export default async function debugService3({ container }: { container: any }) {
  const wallet: any = container.resolve("wallet")
  const repo = wallet.baseRepository_

  console.log("=== baseRepository_ internals ===")
  console.log("own props:", Object.getOwnPropertyNames(repo).join(", "))
  console.log("has manager_:", typeof repo.manager_)
  console.log("has transaction fn:", typeof repo.transaction)
  console.log("has getFreshManager:", typeof repo.getFreshManager)

  const manager = repo.manager_
  if (manager) {
    console.log("\nmanager ctor:", manager.constructor?.name)
    console.log("has getConnection:", typeof manager.getConnection)
    try {
      const r = await manager.getConnection().execute("select 1 as ok")
      console.log("raw select:", JSON.stringify(r.rows ?? r))
    } catch (e: any) {
      console.log("raw select FAILED:", e?.message?.split("\n")[0])
    }

    // Does the repository's own transaction wrapper hand us a manager?
    try {
      await repo.transaction(async (txManager: any) => {
        console.log("  tx arg ctor:", txManager?.constructor?.name)
        const r = await txManager.getConnection().execute("select 2 as ok")
        console.log("  tx raw select:", JSON.stringify(r.rows ?? r))
      })
      console.log("repo.transaction OK")
    } catch (e: any) {
      console.log("repo.transaction FAILED:", e?.message?.split("\n")[0])
    }

    // And the manager's own transaction, which is what my service uses.
    try {
      await manager.transaction(async (tx: any) => {
        const r = await tx.getConnection().execute("select 3 as ok")
        console.log("  manager.transaction raw select:", JSON.stringify(r.rows ?? r))
      })
      console.log("manager.transaction OK")
    } catch (e: any) {
      console.log("manager.transaction FAILED:", e?.message?.split("\n")[0])
    }
  }

  process.exit(0)
}
