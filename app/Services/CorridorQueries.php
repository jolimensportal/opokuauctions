<?php

namespace App\Services;

use App\Models\Lot;
use App\Models\Shipment;
use App\Models\ShipmentMilestone;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * The operator's seven queues.
 *
 * A CRUD admin lists `lots`, `bids`, `wallets`. That is a data model, not a
 * workflow. These queries are organised by what the operator is trying to
 * finish, ordered by how expensive it is to leave something sitting there.
 *
 * The most expensive idle state is "money in, car not moving", which is why
 * Needs-money and Unallocated sit above the shipping queues.
 */
class CorridorQueries
{
    /** Lots sold whose balance has not been paid. Blocks loading. */
    public function needsMoney(int $limit = 25): array
    {
        $rows = Lot::where('status', 'SOLD')
            ->whereNull('balance_settled_at')
            ->with('shipmentLot.shipment')
            ->orderByDesc('current_bid')
            ->limit($limit)
            ->get();

        return [
            'count' => Lot::where('status', 'SOLD')->whereNull('balance_settled_at')->count(),
            'rows' => $rows,
        ];
    }

    /**
     * Paid lots with no container. Money taken, car not moving — the most
     * expensive state in the platform to sit in.
     */
    public function unallocated(int $limit = 25): array
    {
        $base = Lot::where('status', 'SOLD')
            ->whereNotNull('balance_settled_at')
            ->whereDoesntHave('shipmentLot');

        return [
            'count' => (clone $base)->count(),
            'rows' => (clone $base)->orderByDesc('current_bid')->limit($limit)->get(),
        ];
    }

    /** Containers that have not been given a forwarder and a booking yet. */
    public function needsBooking(int $limit = 25): array
    {
        $base = Shipment::where('status', 'BOOKING')
            ->where(function ($query) {
                $query->whereNull('forwarder_id')->orWhereNull('container_no');
            });

        return [
            'count' => (clone $base)->count(),
            'rows' => (clone $base)->limit($limit)->get(),
        ];
    }

    /**
     * Containers at sea, with the last milestone and how stale it is.
     *
     * Staleness is the number that matters operationally: a feed that has not
     * moved in four days is a support queue about to happen.
     */
    public function inTransit(int $limit = 25): array
    {
        // ARRIVED and DISCHARGED deliberately live in atTema(), not here: once
        // a vessel berths the container has stopped being in transit.
        $rows = Shipment::whereIn('status', ['LOADED', 'SAILING'])
            ->orderBy('eta')
            ->limit($limit)
            ->get();

        // Latest milestone per shipment in ONE query. Eager-loading
        // latest()->limit(1) on a hasMany does not reliably scope the limit
        // per parent, which silently returned the oldest milestone instead.
        $lastByShipment = [];
        foreach ($shipmentIds = $rows->pluck('id') as $id) {
            // Tie-break on created_at as well as occurred_at: two milestones
            // can share an instant (a backfill, a bulk import), and "latest"
            // must still be deterministic. The one recorded later is the later
            // fact.
            $lastByShipment[$id] = ShipmentMilestone::where('shipment_id', $id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('created_at')
                ->first(['type', 'occurred_at']);
        }

        $stale = 0;
        foreach ($rows as $shipment) {
            $last = $lastByShipment[$shipment->id] ?? null;
            $shipment->last_milestone = $last?->type;
            $shipment->last_milestone_at = $last?->occurred_at;
            $shipment->days_stale = $last?->occurred_at
                ? (int) $last->occurred_at->diffInDays(now())
                : null;

            // The same rule the logistics console uses, so the two dashboards
            // cannot disagree about whether a container is late. The milestone
            // is passed in because this loop already loaded it.
            if (ShipmentStaleness::isSilentSince($last, $shipment->status)) {
                $stale++;
            }
        }

        return ['count' => $rows->count(), 'rows' => $rows, 'stale' => $stale];
    }

    /** Containers at Tema, clearing customs or waiting for gate-out. */
    public function atTema(int $limit = 25): array
    {
        $base = Shipment::whereIn('status', ['ARRIVED', 'DISCHARGED', 'GATE_OUT']);

        return [
            'count' => (clone $base)->count(),
            'rows' => (clone $base)->orderBy('eta')->limit($limit)->get(),
        ];
    }

    /** Disputed shipments. Funds stay held; nothing here auto-resolves. */
    public function disputed(int $limit = 25): array
    {
        $base = Shipment::where('status', 'DISPUTED');

        return [
            'count' => (clone $base)->count(),
            'rows' => (clone $base)->limit($limit)->get(),
        ];
    }

    /**
     * Wallets whose cached balances disagree with their own ledger.
     *
     * Computed in one pass rather than by calling reconcile() per wallet:
     * this runs on every dashboard load, and N+1 reconciliation over every
     * wallet would be the slowest thing in the admin.
     *
     * `held` is reconstructed the same way WalletService::reconcile does it:
     * HOLD adds to held, RELEASE and CAPTURE remove from it. `total` counts
     * only ownership: DEPOSIT adds, CAPTURE subtracts, HOLD/RELEASE are neutral.
     */
    public function reconciliationDrift(int $limit = 25): array
    {
        $rows = DB::table('wallet')
            ->leftJoin('ledger_entry', 'ledger_entry.wallet_id', '=', 'wallet.id')
            ->groupBy('wallet.id', 'wallet.balance_available', 'wallet.balance_held')
            ->selectRaw('
                wallet.id,
                wallet.user_id,
                wallet.currency_code,
                wallet.balance_available,
                wallet.balance_held,
                COALESCE(SUM(ledger_entry.amount) FILTER (
                    WHERE ledger_entry.type = \'DEPOSIT\'
                ), 0) as deposits,
                COALESCE(ABS(SUM(ledger_entry.amount) FILTER (
                    WHERE ledger_entry.type IN (\'CAPTURE\', \'WITHDRAWAL\')
                )), 0) as outflows,
                COALESCE(ABS(SUM(ledger_entry.amount) FILTER (
                    WHERE ledger_entry.type = \'HOLD\'
                )), 0) as holds,
                COALESCE(ABS(SUM(ledger_entry.amount) FILTER (
                    WHERE ledger_entry.type IN (\'RELEASE\', \'CAPTURE\')
                )), 0) as unholds
            ')
            ->havingRaw('
                (COALESCE(SUM(ledger_entry.amount) FILTER (WHERE ledger_entry.type = \'DEPOSIT\'), 0)
                 - COALESCE(ABS(SUM(ledger_entry.amount) FILTER (WHERE ledger_entry.type IN (\'CAPTURE\',\'WITHDRAWAL\'))), 0))
                <> (wallet.balance_available + wallet.balance_held)
                OR
                (COALESCE(ABS(SUM(ledger_entry.amount) FILTER (WHERE ledger_entry.type = \'HOLD\')), 0)
                 - COALESCE(ABS(SUM(ledger_entry.amount) FILTER (WHERE ledger_entry.type IN (\'RELEASE\',\'CAPTURE\'))), 0))
                <> wallet.balance_held
            ')
            ->orderBy('wallet.user_id')
            ->limit($limit)
            ->get();

        return ['count' => count($rows), 'rows' => $rows];
    }

    /** Everything, for the dashboard header. */
    public function summary(): array
    {
        return [
            'needs_money' => $this->needsMoney()['count'],
            'unallocated' => $this->unallocated()['count'],
            'needs_booking' => $this->needsBooking()['count'],
            'in_transit' => $this->inTransit()['count'],
            'at_tema' => $this->atTema()['count'],
            'disputed' => $this->disputed()['count'],
            'drift' => $this->reconciliationDrift()['count'],
        ];
    }
}
