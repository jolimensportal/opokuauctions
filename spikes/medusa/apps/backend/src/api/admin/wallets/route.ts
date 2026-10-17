import type { MedusaRequest, MedusaResponse } from "@medusajs/framework/http"

/**
 * List wallets for the settlement console.
 *
 * Reads through the wallet module service rather than querying the table
 * directly, so the admin can never bypass the service layer's invariants.
 */
export async function GET(req: MedusaRequest, res: MedusaResponse) {
  const walletService = req.scope.resolve("wallet") as any

  const limit = Math.min(Number(req.query?.limit ?? 50), 200)
  const offset = Number(req.query?.offset ?? 0)
  const currency = (req.query?.currency_code as string) || undefined

  const [wallets, count] = await walletService.listAndCountWallets(
    currency ? { currency_code: currency } : {},
    { skip: offset, take: limit, order: { created_at: "DESC" } }
  )

  // Ledger row count per wallet, in one pass rather than N+1 from the UI.
  const ids = wallets.map((w: any) => w.id)
  const counts = ids.length
    ? await walletService.listLedgerEntries(
        { wallet_id: ids },
        { fields: ["wallet_id"] }
      ).then((rows: any[]) => {
        const tally: Record<string, number> = {}
        for (const r of rows) {
          tally[r.wallet_id] = (tally[r.wallet_id] ?? 0) + 1
        }
        return tally
      })
    : {}

  res.json({
    wallets: wallets.map((w: any) => ({
      ...w,
      total: Number(w.balance_available) + Number(w.balance_held),
      ledger_count: counts[w.id] ?? 0,
    })),
    count,
    offset,
    limit,
  })
}