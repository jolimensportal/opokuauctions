<?php

namespace App\Services;

use App\Models\Bid;
use App\Models\ClearanceTask;
use App\Models\FreightRate;
use App\Models\Lot;
use App\Models\RateCardRow;
use App\Models\ShipmentMilestone;
use App\Models\Wallet;

/**
 * The auction side of the business, today.
 *
 * The counters and the queue are derived from the data so they cannot go
 * stale. The previous required-action list was three sentences typed into a
 * Blade file, and one of them was already wrong: the rate card does have a
 * pickup row — it just charges no freight on top of the collection.
 */
class OperationsOverview
{
    /**
     * @return array{lotsLive: int, openBids: int, moneyOwedCents: int, heldOrDisputed: int}
     */
    public function summary(): array
    {
        return [
            'lotsLive' => Lot::query()->where('status', 'LIVE')->count(),
            'openBids' => Bid::query()->where('status', 'LEADING')->count(),
            'moneyOwedCents' => (int) Lot::query()->where('balance_due_usd', '>', 0)->sum('balance_due_usd'),
            'heldOrDisputed' => Wallet::query()->where('balance_held', '>', 0)->count(),
        ];
    }

    /**
     * Everything that happened, newest first.
     *
     * @return array<int, array{at: string, title: string, detail: string}>
     */
    public function activity(int $limit = 12): array
    {
        $events = [];

        foreach (Bid::query()->with('lot')->latest('created_at')->limit($limit)->get() as $bid) {
            $events[] = [
                'at' => $bid->created_at->toIso8601String(),
                'title' => sprintf('%s bid on %s', $bid->bidder_email, $bid->lot?->title ?? 'a lot'),
                'detail' => '$'.number_format(((int) $bid->amount) / 100, 2),
            ];
        }

        // Three closed lots, not twelve: a timeline of twelve identical lines
        // is a list, not a history.
        foreach (Lot::query()->where('status', 'LIVE')->where('ends_at', '<', now())->latest('ends_at')->limit(3)->get() as $lot) {
            $events[] = [
                'at' => $lot->ends_at->toIso8601String(),
                'title' => sprintf('%s closed', $lot->title),
                'detail' => 'at $'.number_format(((int) $lot->current_bid) / 100, 2),
            ];
        }

        foreach (ClearanceTask::query()->whereNotNull('completed_at')->latest('completed_at')->limit($limit)->get() as $task) {
            $events[] = [
                'at' => $task->completed_at->toIso8601String(),
                'title' => sprintf('%s cleared and receipted', strtolower(str_replace('_', ' ', $task->type))),
                'detail' => '$'.number_format(((int) $task->cost) / 100, 2),
            ];
        }

        foreach (ShipmentMilestone::query()->latest('occurred_at')->limit($limit)->get() as $milestone) {
            $events[] = [
                'at' => $milestone->occurred_at->toIso8601String(),
                'title' => sprintf('Container recorded as %s', strtolower((string) $milestone->type)),
                'detail' => $milestone->notes ?? '',
            ];
        }

        usort($events, fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($events, 0, $limit);
    }

    /**
     * What only an operator can move, derived.
     *
     * @return array<int, array{kind: string, title: string, detail: string, action: string, tag: string, tone: string}>
     */
    public function actions(): array
    {
        return [
            ...$this->rateCardAtCost(),
            ...$this->pickupWithoutFreight(),
            ...$this->closedLotsStillOpen(),
        ];
    }

    /**
     * A rate card row that charges no more than the forwarder costs.
     *
     * @return array<int, array{kind: string, title: string, detail: string, action: string, tag: string, tone: string}>
     */
    private function rateCardAtCost(): array
    {
        $rates = FreightRate::query()->where('active', true)->get();

        if ($rates->isEmpty()) {
            return [];
        }

        $actions = [];

        foreach (RateCardRow::query()->whereNotNull('per_kg_cents')->get() as $row) {
            foreach ($rates as $rate) {
                $costCents = (int) round(((float) $rate->usd_per_kg) * 100);

                if ((int) $row->per_kg_cents > $costCents) {
                    continue;
                }

                $actions[] = [
                    'kind' => 'rate_card_at_cost',
                    'title' => sprintf('The rate card bills at cost on %s', $row->destination),
                    'detail' => sprintf(
                        'The card charges %d c/kg and the forwarder charges %d c/kg. Every kilo we carry earns nothing.',
                        (int) $row->per_kg_cents,
                        $costCents,
                    ),
                    'action' => 'Set the retail rate, then the margin follows.',
                    'tag' => 'zero margin',
                    'tone' => 'bad',
                ];
            }
        }

        return $actions;
    }

    /**
     * A pickup row with no per-kilo charge: the collection is priced, the
     * freight on top of it is not.
     *
     * @return array<int, array{kind: string, title: string, detail: string, action: string, tag: string, tone: string}>
     */
    private function pickupWithoutFreight(): array
    {
        $rows = RateCardRow::query()
            ->where('intake_method', 'partner_pickup')
            ->whereNull('per_kg_cents')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        return $rows->map(fn (RateCardRow $row): array => [
            'kind' => 'pickup_without_freight',
            'title' => 'A pickup is quoted as a flat fee, so no freight is charged on top',
            'detail' => sprintf(
                'The pickup row charges %s as a collection and nothing per kilo, while the forwarder charges the collection and the freight.',
                '$'.number_format(((int) $row->min_charge_cents) / 100, 2),
            ),
            'action' => 'Add the per-kilo charge to the pickup row.',
            'tag' => 'under-priced',
            'tone' => 'warn',
        ])->all();
    }

    /**
     * Auctions that closed and are still open for bids.
     *
     * @return array<int, array{kind: string, title: string, detail: string, action: string, tag: string, tone: string}>
     */
    private function closedLotsStillOpen(): array
    {
        $count = Lot::query()
            ->where('status', 'LIVE')
            ->where('ends_at', '<', now())
            ->count();

        if ($count === 0) {
            return [];
        }

        return [[
            'kind' => 'closed_lot_still_open',
            'title' => sprintf('%d auctions have closed and are still open for bids', $count),
            'detail' => 'Their closing time has passed, so a bid on them now is a bid on something that is over.',
            'action' => 'Close them, or extend them with a stated reason.',
            'tag' => $count.' lots',
            'tone' => 'bad',
        ]];
    }
}
