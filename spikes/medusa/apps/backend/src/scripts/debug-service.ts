export default async function debugService({ container }: { container: any }) {
  const lots: any = container.resolve("lots")
  const wallet: any = container.resolve("wallet")

  console.log("=== wallet service shape ===")
  console.log("own props:", Object.getOwnPropertyNames(wallet).join(", "))
  console.log("\nproto methods:", Object.getOwnPropertyNames(Object.getPrototypeOf(wallet)).slice(0, 30).join(", "))

  for (const key of ["manager_", "__container__", "container_", "manager", "activeManager_"]) {
    const v = (wallet as any)[key]
    console.log(`\nwallet.${key}:`, v === undefined ? "undefined" : typeof v)
  }

  // If manager_ exists, can it run raw SQL?
  const mgr = (wallet as any).manager_
  if (mgr) {
    console.log("\n=== manager_ probe ===")
    console.log("ctor:", mgr?.constructor?.name)
    console.log("has getConnection:", typeof mgr.getConnection)
    try {
      const { rows } = await mgr.getConnection().execute("select 1 as ok")
      console.log("raw query works, rows:", JSON.stringify(rows))
    } catch (e: any) {
      console.log("raw query failed:", e?.message?.split("\n")[0])
    }
    try {
      await mgr.transaction(async (tx: any) => {
        const r = await tx.getConnection().execute("select 2 as ok")
        console.log("transaction works, rows:", JSON.stringify(r.rows ?? r))
      })
    } catch (e: any) {
      console.log("transaction failed:", e?.message?.split("\n")[0])
    }
  }

  process.exit(0)
}
