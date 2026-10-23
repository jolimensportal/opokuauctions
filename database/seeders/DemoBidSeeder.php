<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Lot;
use App\Models\User;
use App\Services\LotsService;
use App\Services\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The bids the inventory seeders paint, made real.
 *
 * The inventory seeders write a bid count and a current bid straight onto the
 * lot, so a lot reads "9 bids, $2,150" on the storefront. There was no bid
 * row behind any of it: the bid table was empty, and of every lot in the
 * database only one - the demo buyer's car - had a recorded winner.
 *
 * A number on a page is not a record. This seeder turns the painted intent
 * into bids: real rows, real bidders, real wallets with money in them. It
 * goes through LotsService::placeBid() rather than writing the columns
 * itself, so the bid rows and the lot's columns cannot drift apart, and it
 * never changes who the winner is - a lot that already names a bidder keeps
 * that bidder, and the history is built so that the last bid is theirs.
 */
class DemoBidSeeder extends Seeder
{
    /** Bidders used for the demo history. Staff accounts do not bid. */
    private const BIDDER_EMAILS = [
        'bidder.one@opokuauctions.test',
        'bidder.two@opokuauctions.test',
        'bidder.three@opokuauctions.test',
    ];

    /** How much each demo bidder is given to bid with, in cents. */
    private const FUNDING_CENTS = 5_000_000;

    public function run(): void
    {
        $lots = Lot::query()
            ->where('status', 'LIVE')
            ->where('bid_count', '>', 0)
            ->where('current_bid', '>', 0)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('bid')->whereColumn('bid.lot_id', 'lot.id'))
            ->get();

        if ($lots->isEmpty()) {
            return;
        }

        $bidders = $this->bidders();
        $service = app(LotsService::class);
        $wallet = app(WalletService::class);

        foreach ($lots as $lot) {
            $this->materialiseBids($lot, $bidders, $service, $wallet);
        }
    }

    /**
     * The demo buyers, funded once.
     *
     * @return array<int, User>
     */
    private function bidders(): array
    {
        $bidders = [];
        $wallet = app(WalletService::class);

        foreach (self::BIDDER_EMAILS as $email) {
            $bidder = User::where('email', $email)->first();

            if (! $bidder) {
                $bidder = User::create([
                    'name' => 'Demo bidder '.str($email)->after('bidder.')->before('@')->title(),
                    'email' => $email,
                    'password' => 'opoku-demo-bidder',
                    'role' => Role::Buyer,
                    'country_code' => 'GH',
                ]);
            }

            $wallet->ensureWallet((string) $bidder->id, 'usd');

            // Funded once. A second run of this seeder must not double the
            // money in the demo wallets.
            if ($wallet->reconcile((string) $bidder->id, 'usd')['wallet']['available'] === 0) {
                $wallet->deposit((string) $bidder->id, self::FUNDING_CENTS, 'usd', ['id' => 'demo-funding']);
            }

            $bidders[] = $bidder;
        }

        return $bidders;
    }

    /**
     * Write the lot's painted bid history as real bids.
     *
     * @param  array<int, User>  $bidders
     */
    private function materialiseBids(Lot $lot, array $bidders, LotsService $service, WalletService $wallet): void
    {
        $count = (int) $lot->bid_count;
        $target = (int) $lot->current_bid;
        $starting = (int) $lot->starting_bid;
        $increment = max(1, (int) $lot->bid_increment);

        // A painted bid below the starting price is not a history that can be
        // written. The lot stays as it is rather than being given a bid
        // nobody could have made.
        if ($target < $starting) {
            return;
        }

        $amounts = $this->amounts($starting, $target, $count, $increment);
        $assignments = $this->assignBidders($lot, $bidders, count($amounts));

        // The clock is moved forward while the history is written: placeBid
        // refuses a lot whose time is up, and the point of this seeder is to
        // stage history for lots that have since closed. The original close
        // time is put back afterwards.
        $originalEndsAt = $lot->ends_at;

        DB::transaction(function () use ($lot, $amounts, $assignments, $service, &$originalEndsAt) {
            $lot->update([
                'ends_at' => Carbon::now()->addDays(30),

                // The painted figures are the intent, not a starting state.
                // The lot goes back to "nobody has bid" so the history below
                // can be written as a rising series of real bids, which is
                // what rebuilds the same current bid and bid count from
                // records.
                'current_bid' => 0,
                'bid_count' => 0,
                'highest_bidder_id' => null,
            ]);

            foreach ($amounts as $index => $amount) {
                $bidder = $assignments[$index];

                $service->placeBid([
                    'lot_id' => $lot->id,
                    'bidder_id' => (string) $bidder->id,
                    'bidder_email' => (string) $bidder->email,
                    'amount' => $amount,
                    'idempotency_key' => 'demo-bid-'.$lot->id.'-'.$index,
                ]);
            }

            $lot->update(['ends_at' => $originalEndsAt]);
        });
    }

    /**
     * The amounts of the history: a rising series that lands exactly on the
     * painted current bid.
     *
     * The painted count is capped at what the increment can actually produce.
     * A lot painted with 14 bids, a $100 increment and a spread of $650 cannot
     * have both: fourteen bids rising by the increment land far above the
     * painted price. Writing fewer real bids is honest; writing bids
     * placeBid() would refuse is not.
     *
     * @return array<int, int>
     */
    private function amounts(int $starting, int $target, int $count, int $increment): array
    {
        $maximum = intdiv(max(0, $target - $starting), $increment) + 1;
        $count = min($count, $maximum);

        $amounts = [];

        for ($index = 0; $index < $count; $index++) {
            $amounts[] = $target - (($count - 1 - $index) * $increment);
        }

        return $amounts;
    }

    /**
     * Who made each bid.
     *
     * Two rules, and both matter. The last bid is the lot's winner, because
     * the highest bidder is the last one to bid. And no bidder may bid
     * twice in a row, because placeBid refuses a bidder who is already the
     * leader.
     *
     * @param  array<int, User>  $bidders
     * @return array<int, User>
     */
    private function assignBidders(Lot $lot, array $bidders, int $count): array
    {
        $winner = blank($lot->highest_bidder_id)
            ? null
            : User::find($lot->highest_bidder_id);

        $assignments = [];

        for ($index = 0; $index < $count; $index++) {
            $isLast = $index === $count - 1;

            if ($isLast && $winner) {
                $previous = $assignments[$index - 1] ?? null;

                // The winner cannot be the bidder who is already leading.
                if ($previous && $previous->id === $winner->id) {
                    $assignments[$index - 1] = $this->otherThan($bidders, $winner);
                }

                $assignments[$index] = $winner;

                continue;
            }

            $previous = $assignments[$index - 1] ?? null;
            $assignments[$index] = $this->otherThan($bidders, $previous);
        }

        return $assignments;
    }

    /**
     * The first demo bidder who is not the one to avoid.
     *
     * @param  array<int, User>  $bidders
     */
    private function otherThan(array $bidders, ?User $avoid): User
    {
        foreach ($bidders as $bidder) {
            if (! $avoid || $bidder->id !== $avoid->id) {
                return $bidder;
            }
        }

        return $bidders[0];
    }
}
