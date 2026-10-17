import type { MedusaRequest, MedusaResponse } from "@medusajs/framework/http"

/**
 * Reconcile one wallet against its ledger.
 *
 * This is the check that catches a money bug before a customer does: it
 * re-derives the balance from the append-only ledger and compares it to the
 * cached columns, reporting any drift.
 */
export async function POST(req: MedusaRequest, res: MedusaResponse) {
  const walletService = req.scope.resolve("wallet") as any
  const walletId = req.params.id

  const [wallet] = await walletService.listWallets({ id: walletId }, { take: 1 })
  if (!wallet) {
    res.status(404).json({ message: "Wallet not found" })
    return
  }

  const result = await walletService.reconcile(wallet.user_id, wallet.currency_code)

  res.json(result)
}