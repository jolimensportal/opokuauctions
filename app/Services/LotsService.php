<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Bid;
use App\Models\Lot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Auction service: lots, bids, and closing.
 *
 * The bid path is the part that has to be exactly right, so the rules are
 * spelled out in order:
 *
 *  1. Lock the lot row FOR UPDATE. Without it, two simultaneous bids can both
 *     read current_bid and both write current_bid + increment, so the loser
 *     silently disappears.
 *  2. Re-read the lot inside the lock. Never trust a value read before it.
 *  3. Refuse if the lot is not LIVE, if it has closed, or if the amount does
 *     not beat current_bid by at least bid_increment.
 *  4. Idempotency: a replayed key returns the original bid. The unique index on
 *     idempotency_key is the real guarantee; the pre-check is just fast.
 *  5. Anti-snipe: a bid landing inside the final window extends the close.
 *
 * Money is NOT touched here. Holding funds is WalletService's job, in a
 * separate transaction, so a payment failure cannot leave a bid accepted
 * against an unfunded bidder.
 */
class LotsService
{
    /** Open a lot for bidding: UPCOMING -> LIVE. */
    public function openLot(string $lotId): Lot
    {
        return DB::transaction(function () use ($lotId) {
            $lot = $this->lockLot($lotId);

            if ($lot->status !== 'UPCOMING') {
                throw new RuntimeException("Lot {$lotId} is {$lot->status}, expected UPCOMING");
            }
            if ($lot->starts_at->isFuture()) {
                throw new RuntimeException("Lot {$lotId} has not started yet");
            }

            $lot->status = 'LIVE';
            $lot->save();

            return $lot;
        });
    }

    /**
     * Place a bid. Returns the bid, the updated lot, who was outbid, and
     * whether the clock was extended.
     */
    public function placeBid(array $params): array
    {
        $lotId = $params['lot_id'];
        $bidderId = $params['bidder_id'];
        $bidderEmail = $params['bidder_email'];
        $amount = $params['amount'];  // int|float on purpose: see WalletService
        $idempotencyKey = $params['idempotency_key'] ?? null;

        if (! is_int($amount) || $amount <= 0) {
            throw new RuntimeException('Bid must be a positive integer');
        }

        return DB::transaction(function () use (
            $lotId, $bidderId, $bidderEmail, $amount, $idempotencyKey
        ) {
            // Idempotency: a retry must not double-bid.
            if ($idempotencyKey !== null) {
                $prior = Bid::where('idempotency_key', $idempotencyKey)->first();
                if ($prior) {
                    return [
                        'bid' => $prior->toArray(),
                        'lot' => Lot::where('id', $lotId)->first()?->toArray(),
                        'previous_bidder_id' => null,
                        'extension' => false,
                    ];
                }
            }

            $lot = $this->lockLot($lotId);
            $now = now();

            if ($lot->status !== 'LIVE') {
                throw new RuntimeException("Lot is {$lot->status}, not accepting bids");
            }
            if ($lot->ends_at->lessThanOrEqualTo($now)) {
                throw new RuntimeException('Auction has ended');
            }
            if ($lot->highest_bidder_id === $bidderId) {
                throw new RuntimeException('You are already the highest bidder');
            }

            $minimum = $lot->minimumNextBid();

            if ($amount < $minimum) {
                throw new RuntimeException(sprintf(
                    'Bid must be at least %d (current %d, increment %d)',
                    $minimum,
                    $lot->current_bid,
                    $lot->bid_increment
                ));
            }

            // Anti-snipe: inside the window, push the clock out.
            $msRemaining = $lot->ends_at->getTimestamp() * 1000 - $now->getTimestamp() * 1000;
            $shouldExtend = $msRemaining <= AntiSnipe::WINDOW_MS;
            $newEndsAt = $shouldExtend
                ? $now->copy()->addMilliseconds(AntiSnipe::EXTENSION_MS)
                : $lot->ends_at;

            $bid = Bid::create([
                'id' => 'bid_'.Str::random(16),
                'lot_id' => $lotId,
                'bidder_id' => $bidderId,
                'bidder_email' => $bidderEmail,
                'amount' => $amount,
                'currency_code' => $lot->currency_code,
                'status' => 'ACCEPTED',
                'caused_extension' => $shouldExtend,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Demote the previous leader. Bids stay in the table; only status moves.
            if ($lot->highest_bidder_id) {
                Bid::where('lot_id', $lotId)
                    ->where('bidder_id', $lot->highest_bidder_id)
                    ->where('status', 'ACCEPTED')
                    ->update(['status' => 'OUTBID', 'updated_at' => $now]);
            }

            $lot->current_bid = $amount;
            $lot->highest_bidder_id = $bidderId;
            $lot->bid_count = $lot->bid_count + 1;
            $lot->ends_at = $newEndsAt;
            $lot->extensions = $lot->extensions + ($shouldExtend ? 1 : 0);
            $lot->save();

            // Read back through the same transaction so the caller sees the
            // committed values rather than a stale in-memory copy.
            $lot->refresh();

            return [
                'bid' => $bid->toArray(),
                'lot' => $lot->toArray(),
                'previous_bidder_id' => $lot->getOriginal('highest_bidder_id'),
                'extension' => $shouldExtend,
            ];
        });
    }

    /**
     * Close an expired auction.
     *   reserve met -> SOLD
     *   otherwise   -> RESERVE_NOT_MET
     * Safe to call repeatedly: the status guard makes the second call a no-op.
     */
    public function finalizeLot(string $lotId): array
    {
        return DB::transaction(function () use ($lotId) {
            $lot = $this->lockLot($lotId);

            if ($lot->status !== 'LIVE') {
                return ['status' => $lot->status, 'winner_id' => null];
            }
            if ($lot->ends_at->isFuture()) {
                throw new RuntimeException('Lot has not expired yet');
            }

            $reserveMet = $lot->bid_count > 0 && $lot->current_bid >= $lot->reserve_price;

            // A sale needs a buyer. A lot can carry a bid count and a current
            // bid with no bid row behind it - the demo data used to, and a
            // figure is a number, not a record. Selling that would record a
            // sale to nobody, and the money panels would then chase a
            // customer who does not exist. It stays open instead, and the
            // close command reports it for a human to look at.
            if ($reserveMet && blank($lot->highest_bidder_id)) {
                throw new RuntimeException("Lot {$lotId} has a reserve-met bid but no recorded bidder");
            }

            $sold = $reserveMet;
            $status = $sold ? 'SOLD' : 'RESERVE_NOT_MET';

            $lot->status = $status;
            $lot->save();

            if ($sold) {
                $top = Bid::where('lot_id', $lotId)
                    ->orderByDesc('amount')
                    ->orderBy('created_at')
                    ->first();

                if ($top) {
                    $top->status = 'WINNING';
                    $top->save();
                }
            }

            return [
                'status' => $status,
                'winner_id' => $sold ? $lot->highest_bidder_id : null,
            ];
        });
    }

    /** Every live lot whose close time has passed. Used by the cron job. */
    public function findExpiredLiveLots(int $limit = 100): array
    {
        return Lot::where('status', 'LIVE')
            ->where('ends_at', '<=', now())
            ->orderBy('ends_at')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /** Lock and read a lot. The lock is the whole ballgame for bid safety. */
    private function lockLot(string $lotId): Lot
    {
        $lot = Lot::where('id', $lotId)->lockForUpdate()->first();

        if (! $lot) {
            throw new RuntimeException("Lot {$lotId} not found");
        }

        return $lot;
    }
}
