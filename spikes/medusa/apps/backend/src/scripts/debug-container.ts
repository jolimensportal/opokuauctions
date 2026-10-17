export default async function debugContainer({ container }: { container: any }) {
  console.log("=== container diagnostics ===")
  console.log("typeof container:", typeof container)
  console.log("has resolve fn:", typeof container?.resolve)
  console.log("ctor:", container?.constructor?.name)
  console.log("own keys:", Object.keys(container ?? {}).slice(0, 20).join(", "))

  const awilix = container as any
  if (typeof awilix?.registrations === "object") {
    console.log("\n=== registered names (lots/wallet) ===")
    const names = Object.keys(awilix.registrations)
    console.log("total registrations:", names.length)
    console.log(
      names
        .filter((n) => /lot|wallet|bid|ledger/i.test(n))
        .join(", ") || "(no lot/wallet registrations found)"
    )
    console.log("\nfirst 40 names:", names.slice(0, 40).join(", "))
  } else {
    console.log("\nno .registrations — keys:", Object.keys(awilix ?? {}).slice(0, 30).join(", "))
  }

  console.log("\n=== try resolving ===")
  for (const key of ["lots", "wallet", "manager"]) {
    try {
      const svc = container.resolve(key)
      console.log(`resolve("${key}") ->`, svc?.constructor?.name ?? typeof svc)
    } catch (e: any) {
      console.log(`resolve("${key}") FAILED:`, e?.message)
    }
  }

  process.exit(0)
}
