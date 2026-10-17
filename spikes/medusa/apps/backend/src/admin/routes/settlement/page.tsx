import * as React from "react"
import type { FC } from "react"
import {
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query"
// `sdk` is the session-bound client from the admin SDK. It is NOT exported by
// @medusajs/js-sdk, which only ships Admin/Store/Auth/Client classes. Importing
// it from the wrong package builds in dev but fails the production bundle.
import { defineRouteConfig, sdk } from "@medusajs/admin-sdk"

/**
 * The settlement console: every buyer's money, where it is, and whether the
 * cached balances still agree with the immutable ledger.
 *
 * "Reconcile" is the action that matters. It re-derives the balance from the
 * ledger and reports drift, which is the one check that catches a money bug
 * before a customer does.
 *
 * Compare with Filament: there this whole screen is one generated resource
 * plus a table definition. Here the table, the row rendering, the money
 * formatting, the action and its feedback are all hand-written React, because
 * the admin only ships extensions, not a resource generator.
 */

type Wallet = {
  id: string
  user_id: string
  currency_code: string
  balance_available: number
  balance_held: number
  total: number
  ledger_count: number
}

type ReconcileResult = {
  wallet: { available: number; held: number }
  ledger: { available: number; held: number }
  ok: boolean
}

/** Minor units are meaningless to a human, so format on the way out. */
const money = (minor: number, currency: string) =>
  new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: currency.toUpperCase(),
  }).format(Number(minor) / 100)

const tableStyle: React.CSSProperties = {
  width: "100%",
  borderCollapse: "collapse",
  fontSize: "14px",
}

const thStyle: React.CSSProperties = {
  textAlign: "left",
  padding: "8px 12px",
  borderBottom: "1px solid var(--medusa-border-color, #e2e8f0)",
  fontWeight: 600,
  whiteSpace: "nowrap",
}

const tdStyle: React.CSSProperties = {
  padding: "8px 12px",
  borderBottom: "1px solid var(--medusa-border-color, #e2e8f0)",
}

const SettlementConsole: FC = () => {
  const queryClient = useQueryClient()
  const [feedback, setFeedback] = React.useState<{
    tone: "ok" | "drift"
    text: string
  } | null>(null)

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ["settle-wallets"],
    queryFn: () =>
      sdk.admin.fetch("/wallets", { method: "GET", query: { limit: 50 } }),
  })

  const reconcile = useMutation({
    mutationFn: async (walletId: string) =>
      (await sdk.admin.fetch(`/wallets/${walletId}/reconcile`, {
        method: "POST",
      })) as ReconcileResult,
    onSuccess: (result) => {
      setFeedback(
        result.ok
          ? {
              tone: "ok",
              text: "Wallet reconciles: ledger and balances agree.",
            }
          : {
              tone: "drift",
              text: `DRIFT DETECTED — ledger says ${result.ledger.available}/${result.ledger.held}, wallet says ${result.wallet.available}/${result.wallet.held}.`,
            }
      )
      void queryClient.invalidateQueries({ queryKey: ["settle-wallets"] })
    },
    onError: (err: Error) =>
      setFeedback({ tone: "drift", text: `Reconcile failed: ${err.message}` }),
  })

  const wallets: Wallet[] = data?.wallets ?? []

  return (
    <div style={{ padding: "24px" }}>
      <h1 style={{ fontSize: "20px", marginBottom: "4px" }}>Settlement</h1>
      <p style={{ color: "#64748b", marginBottom: "16px" }}>
        Balances cached on the wallet, checked against the append-only ledger.
      </p>

      {feedback && (
        <div
          role="alert"
          style={{
            padding: "10px 12px",
            borderRadius: "6px",
            marginBottom: "16px",
            border: `1px solid ${feedback.tone === "ok" ? "#16a34a" : "#dc2626"}`,
            color: feedback.tone === "ok" ? "#15803d" : "#b91c1c",
            background: feedback.tone === "ok" ? "#f0fdf4" : "#fef2f2",
          }}
        >
          {feedback.text}
        </div>
      )}

      {isLoading && <p>Loading wallets…</p>}
      {isError && <p style={{ color: "#b91c1c" }}>{(error as Error)?.message}</p>}

      {!isLoading && !isError && (
        <table style={tableStyle}>
          <thead>
            <tr>
              <th style={thStyle}>Buyer</th>
              <th style={thStyle}>Currency</th>
              <th style={thStyle}>Available</th>
              <th style={thStyle}>Held</th>
              <th style={thStyle}>Total</th>
              <th style={thStyle}>Ledger rows</th>
              <th style={thStyle}>Reconcile</th>
            </tr>
          </thead>
          <tbody>
            {wallets.length === 0 && (
              <tr>
                <td style={tdStyle} colSpan={7}>
                  No wallets yet.
                </td>
              </tr>
            )}
            {wallets.map((w) => (
              <tr key={w.id}>
                <td style={tdStyle}>{w.user_id}</td>
                <td style={tdStyle}>{w.currency_code.toUpperCase()}</td>
                <td style={tdStyle}>
                  {money(w.balance_available, w.currency_code)}
                </td>
                <td
                  style={{
                    ...tdStyle,
                    color: w.balance_held > 0 ? "#b45309" : undefined,
                  }}
                >
                  {money(w.balance_held, w.currency_code)}
                </td>
                <td style={tdStyle}>{money(w.total, w.currency_code)}</td>
                <td style={tdStyle}>{w.ledger_count}</td>
                <td style={tdStyle}>
                  <button
                    type="button"
                    disabled={reconcile.isPending}
                    onClick={() => reconcile.mutate(w.id)}
                    style={{
                      padding: "4px 10px",
                      borderRadius: "4px",
                      border: "1px solid #0f172a",
                      background: "#0f172a",
                      color: "#fff",
                      cursor: reconcile.isPending ? "wait" : "pointer",
                    }}
                  >
                    {reconcile.isPending ? "Checking…" : "Reconcile"}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}

export default SettlementConsole

// Route config must live in page.tsx: the Medusa linter rejects a sibling
// route.tsx under src/admin/routes/ outright.
export const config = defineRouteConfig({
  label: "Settlement",
  icon: "receipt-long",
})