import type { MedusaRequest, MedusaResponse } from "@medusajs/framework/http"

/**
 * Ledger entries for the settlement console.
 *
 * Read-only by design. There is no POST/PUT/DELETE for this collection, so
 * the source of truth cannot be rewritten from the admin.
 */
export async function GET(req: MedusaRequest, res: MedusaResponse) {
  const walletService = req.scope.resolve("wallet") as any

  const limit = Math.min(Number(req.query?.limit ?? 100), 500)
  const offset = Number(req.query?.offset ?? 0)

  const filters: Record<string, unknown> = {}
  if (req.query?.wallet_id) filters.wallet_id = req.query.wallet_id
  if (req.query?.type) filters.type = req.query.type

  const [entries, count] = await walletService.listAndCountLedgerEntries(filters, {
    skip: offset,
    take: limit,
    order: { created_at: "DESC" },
  })

  res.json({ entries, count, offset, limit })
}