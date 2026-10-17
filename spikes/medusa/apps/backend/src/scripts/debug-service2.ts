export default async function debugService2({ container }: { container: any }) {
  const wallet: any = container.resolve("wallet")

  console.log("=== baseRepository_ probe ===")
  const repo = wallet.baseRepository_
  console.log("ctor:", repo?.constructor?.name)
  console.log("methods:", Object.getOwnPropertyNames(Object.getPrototypeOf(repo) ?? {}).join(", "))

  const manager = repo?.getManager?.()
  console.log("\nmanager via getManager():", manager?.constructor?.name)
  if (manager) {
    console.log("  has getConnection:", typeof manager.getConnection)
    try {
      const r = await manager.getConnection().execute("select 1 as ok")
      console.log("  raw query:", JSON.stringify(r.rows ?? r))
    } catch (e: any) {
      console.log("  raw query failed:", e?.message?.split("\n")[0])
    }
    try {
      await manager.transaction(async (tx: any) => {
        const r = await tx.getConnection().execute("select 2 as ok")
        console.log("  transaction:", JSON.stringify(r.rows ?? r))
      })
    } catch (e: any) {
      console.log("  transaction failed:", e?.message?.split("\n")[0])
    }
  }

  // And confirm the scoped container cannot resolve by name.
  console.log("\n=== scoped container probe ===")
  const sc = wallet.__container__
  console.log("typeof sc.resolve:", typeof sc.resolve)
  try {
    console.log("sc.resolve('wallet'):", sc.resolve("wallet")?.constructor?.name)
  } catch (e: any) {
    console.log("sc.resolve('wallet') failed:", e?.message?.split("\n")[0])
  }

  process.exit(0)
}
