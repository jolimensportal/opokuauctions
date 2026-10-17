<?php

namespace App\Spike;

use App\Models\Lot;
use App\Services\LotsService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OpokuAuctions spike check — stack B (Laravel 13).
 *
 * This is the twin of `spikes/medusa/apps/backend/src/scripts/spike-check.ts`.
 * Same 21 checks, same order, same error codes, same money amounts. Any
 * difference in the output is a difference in the stack, not in the test.
 */
class SpikeCheck
{
    private int $passed = 0;

    private int $failed = 0;

    /** @var string[] */
    private array $failures = [];

    private LotsService $lots;

    private WalletService $wallet;

    private ProcessRace $race;

    /** Unique-ish suffix so repeated runs never collide on ids or emails. */
    private string $run;

    public function __construct(LotsService $lots, WalletService $wallet, ProcessRace $race)
    {
        $this->lots = $lots;
        $this->wallet = $wallet;
        $this->race = $race;
        $this->run = 'r'.Str::lower(Str::random(8));
    }

    public function run(): int
    {
        echo "Running checks against the Laravel app.\n\n";

        $this->walletLedgerChecks();
        $this->bidPlacementChecks();
        $this->antiSnipeChecks();
        $this->finalizationChecks();
        $this->concurrencyChecks();

        $this->report();

        return $this->failed === 0 ? 0 : 1;
    }

    // ------------------------------------------------------------------ suites

    private function walletLedgerChecks(): void
    {
        echo "wallet ledger\n";

        $this->check('deposit credits balance and writes one ledger row', function () {
            $u = $this->run.'-dep';
            $this->wallet->ensureWallet($u);
            $res = $this->wallet->deposit($u, 100000, 'usd', ['id' => 'pay_1']);

            $this->same(100000, $res['available'], 'available');
            $this->same(0, $res['held'], 'held');

            $entries = DB::table('ledger_entry')->where('user_id', $u)->get();
            $this->same(1, count($entries), 'ledger row count');
            $this->same('DEPOSIT', $entries[0]->type, 'entry type');
            $this->same(100000, (int) $entries[0]->balance_after, 'balance_after');
        });

        $this->check('hold moves money between buckets, total unchanged', function () {
            $u = $this->run.'-hold';
            $this->wallet->ensureWallet($u);
            $this->wallet->deposit($u, 100000);
            $res = $this->wallet->hold($u, 40000, 'usd', ['id' => 'b1']);

            $this->same(60000, $res['available'], 'available');
            $this->same(40000, $res['held'], 'held');
            $this->same(100000, $res['available'] + $res['held'], 'total');
        });

        $this->check('cannot hold more than the buyer has', function () {
            $u = $this->run.'-poor';
            $this->wallet->ensureWallet($u);
            $this->wallet->deposit($u, 10000);
            $this->throwsCode(
                fn () => $this->wallet->hold($u, 50000, 'usd', ['id' => 'x']),
                'Insufficient funds'
            );
        });

        $this->check('release returns held funds to available', function () {
            $u = $this->run.'-rel';
            $this->wallet->ensureWallet($u);
            $this->wallet->deposit($u, 100000);
            $this->wallet->hold($u, 40000, 'usd', ['id' => 'b1']);
            $res = $this->wallet->release($u, 40000, 'usd', ['id' => 'outbid']);

            $this->same(100000, $res['available'], 'available');
            $this->same(0, $res['held'], 'held');
        });

        $this->check('capture removes held funds permanently', function () {
            $u = $this->run.'-cap';
            $this->wallet->ensureWallet($u);
            $this->wallet->deposit($u, 100000);
            $this->wallet->hold($u, 40000, 'usd', ['id' => 'b1']);
            $res = $this->wallet->capture($u, 40000, 'usd', ['id' => 'settle']);

            $this->same(60000, $res['available'], 'available');
            $this->same(0, $res['held'], 'held');
        });

        $this->check('wallet reconciles against its own ledger', function () {
            $u = $this->run.'-rec';
            $this->wallet->ensureWallet($u);
            $this->wallet->deposit($u, 100000);
            $this->wallet->hold($u, 30000, 'usd', ['id' => 'b1']);
            $this->wallet->hold($u, 20000, 'usd', ['id' => 'b2']);
            $this->wallet->release($u, 20000, 'usd', ['id' => 'b2-out']);
            $this->wallet->capture($u, 30000, 'usd', ['id' => 'win']);

            $r = $this->wallet->reconcile($u);
            $this->same(true, $r['ok'], 'reconciled');
            $this->same($r['wallet']['available'], $r['ledger']['available'], 'available matches');
            $this->same($r['wallet']['held'], $r['ledger']['held'], 'held matches');
        });

        $this->check('rejects a fractional cent', function () {
            $u = $this->run.'-frac';
            $this->wallet->ensureWallet($u);
            $this->throwsCode(
                fn () => $this->wallet->deposit($u, 10.5, 'usd'),
                'positive integer'
            );
        });
    }

    private function bidPlacementChecks(): void
    {
        echo "\nbid placement\n";

        $this->check('first bid is accepted at the starting price', function () {
            $lot = $this->makeLot();
            $this->lots->openLot($lot->id);
            $res = $this->lots->placeBid([
                'lot_id' => $lot->id, 'bidder_id' => $this->run.'-alice',
                'bidder_email' => 'a@e.com', 'amount' => 10000,
            ]);

            $this->same(10000, $res['lot']['current_bid'], 'current_bid');
            $this->same(1, $res['lot']['bid_count'], 'bid_count');
        });

        $this->check('bid below the increment is rejected', function () {
            $lot = $this->makeLot();
            $this->lots->openLot($lot->id);
            $this->lots->placeBid([
                'lot_id' => $lot->id, 'bidder_id' => $this->run.'-a1',
                'bidder_email' => 'a@e.com', 'amount' => 10000,
            ]);
            $this->throwsCode(
                fn () => $this->lots->placeBid([
                    'lot_id' => $lot->id, 'bidder_id' => $this->run.'-b1',
                    'bidder_email' => 'b@e.com', 'amount' => 11000,
                ]),
                'at least 12500'
            );
        });

        $this->check('highest bidder cannot re-bid on their own lot', function () {
            $lot = $this->makeLot();
            $this->lots->openLot($lot->id);
            $u = $this->run.'-self';
            $this->lots->placeBid([
                'lot_id' => $lot->id, 'bidder_id' => $u,
                'bidder_email' => 'a@e.com', 'amount' => 10000,
            ]);
            $this->throwsCode(
                fn () => $this->lots->placeBid([
                    'lot_id' => $lot->id, 'bidder_id' => $u,
                    'bidder_email' => 'a@e.com', 'amount' => 20000,
                ]),
                'already the highest'
            );
        });

        $this->check('outbidding demotes the previous leader', function () {
            $lot = $this->makeLot();
            $this->lots->openLot($lot->id);
            $this->lots->placeBid([
                'lot_id' => $lot->id, 'bidder_id' => $this->run.'-a2',
                'bidder_email' => 'a@e.com', 'amount' => 10000,
            ]);
            $this->lots->placeBid([
                'lot_id' => $lot->id, 'bidder_id' => $this->run.'-b2',
                'bidder_email' => 'b@e.com', 'amount' => 12500,
            ]);

            $a = DB::table('bid')->where('lot_id', $lot->id)
                ->where('bidder_id', $this->run.'-a2')->first();
            $b = DB::table('bid')->where('lot_id', $lot->id)
                ->where('bidder_id', $this->run.'-b2')->first();

            $this->same('OUTBID', $a->status, 'previous leader status');
            $this->same('ACCEPTED', $b->status, 'new leader status');
        });

        $this->check('replaying an idempotency key does not double-bid', function () {
            $lot = $this->makeLot();
            $this->lots->openLot($lot->id);
            $key = $this->run.'-idem';

            $first = $this->lots->placeBid([
                'lot_id' => $lot->id, 'bidder_id' => $this->run.'-i1',
                'bidder_email' => 'a@e.com', 'amount' => 10000,
                'idempotency_key' => $key,
            ]);
            $second = $this->lots->placeBid([
                'lot_id' => $lot->id, 'bidder_id' => $this->run.'-i1',
                'bidder_email' => 'a@e.com', 'amount' => 10000,
                'idempotency_key' => $key,
            ]);

            $this->same($first['bid']['id'], $second['bid']['id'], 'same bid returned');
            $this->same(1, DB::table('bid')->where('lot_id', $lot->id)->count(), 'bid rows');
        });

        $this->check('bid on a closed auction is rejected', function () {
            $lot = $this->makeLot();
            $this->lots->openLot($lot->id);
            $this->expire($lot->id);
            $this->throwsCode(
                fn () => $this->lots->placeBid([
                    'lot_id' => $lot->id, 'bidder_id' => $this->run.'-z',
                    'bidder_email' => 'a@e.com', 'amount' => 10000,
                ]),
                'ended'
            );
        });
    }

    private function antiSnipeChecks(): void
    {
        echo "\nanti-snipe\n";

        $this->check('bid inside the final window pushes the close time out', function () {
            $lot = $this->makeLot(['ends_at' => now()->addSeconds(60)]);
            $this->lots->openLot($lot->id);
            $before = $lot->ends_at->getTimestamp();

            $res = $this->lots->placeBid([
                'lot_id' => $lot->id, 'bidder_id' => $this->run.'-sn1',
                'bidder_email' => 'a@e.com', 'amount' => 10000,
            ]);

            $this->same(true, $res['extension'], 'extension flag');
            $this->same(1, $res['lot']['extensions'], 'extensions');
            $this->assertTrue(
                strtotime($res['lot']['ends_at']) > $before,
                'ends_at did not move'
            );
        });

        $this->check('bid outside the window does not extend', function () {
            $lot = $this->makeLot(['ends_at' => now()->addHour()]);
            $this->lots->openLot($lot->id);
            $res = $this->lots->placeBid([
                'lot_id' => $lot->id, 'bidder_id' => $this->run.'-sn2',
                'bidder_email' => 'a@e.com', 'amount' => 10000,
            ]);

            $this->same(false, $res['extension'], 'extension flag');
            $this->same(0, $res['lot']['extensions'], 'extensions');
        });
    }

    private function finalizationChecks(): void
    {
        echo "\nfinalization\n";

        $this->check('reserve met closes as SOLD', function () {
            $lot = $this->makeLot();
            $this->lots->openLot($lot->id);
            $this->lots->placeBid([
                'lot_id' => $lot->id, 'bidder_id' => $this->run.'-w1',
                'bidder_email' => 'w@e.com', 'amount' => 50000,
            ]);
            $this->expire($lot->id);

            $res = $this->lots->finalizeLot($lot->id);
            $this->same('SOLD', $res['status'], 'status');
            $this->same($this->run.'-w1', $res['winner_id'], 'winner');
        });

        $this->check('top bid under reserve closes as RESERVE_NOT_MET', function () {
            $lot = $this->makeLot(['reserve_price' => 90000]);
            $this->lots->openLot($lot->id);
            $this->lots->placeBid([
                'lot_id' => $lot->id, 'bidder_id' => $this->run.'-w2',
                'bidder_email' => 'w@e.com', 'amount' => 10000,
            ]);
            $this->expire($lot->id);

            $res = $this->lots->finalizeLot($lot->id);
            $this->same('RESERVE_NOT_MET', $res['status'], 'status');
            $this->same(null, $res['winner_id'], 'winner');
        });

        $this->check('finalizing twice is a no-op', function () {
            $lot = $this->makeLot();
            $this->lots->openLot($lot->id);
            $this->lots->placeBid([
                'lot_id' => $lot->id, 'bidder_id' => $this->run.'-w3',
                'bidder_email' => 'w@e.com', 'amount' => 60000,
            ]);
            $this->expire($lot->id);

            $a = $this->lots->finalizeLot($lot->id);
            $b = $this->lots->finalizeLot($lot->id);
            $this->same('SOLD', $a['status'], 'first close');
            $this->same('SOLD', $b['status'], 'second close');
        });

        $this->check('cron query finds only expired live lots', function () {
            $expired = $this->makeLot(['ends_at' => now()->subSecond()]);
            $this->lots->openLot($expired->id);
            $live = $this->makeLot(['ends_at' => now()->addHour()]);
            $this->lots->openLot($live->id);

            // Pass a limit that cannot truncate this run's lot. The cron query
            // batches oldest-first and caps at 100 by default, which is correct
            // in production (the next run picks up the remainder) but means a
            // long-lived dev database full of expired lots can push a fresh one
            // out of the first batch.
            $ids = $this->lots->findExpiredLiveLots(100000);
            $this->assertTrue(in_array($expired->id, $ids, true), 'expired lot not returned');
            $this->assertTrue(! in_array($live->id, $ids, true), 'live lot wrongly returned');
        });
    }

    private function concurrencyChecks(): void
    {
        echo "\nconcurrency\n";

        $this->check('six buyers cannot overdraw one wallet', function () {
            $u = $this->run.'-race';
            $this->wallet->ensureWallet($u);
            $this->wallet->deposit($u, 10000);

            // Six REAL processes, released together by a shared barrier, each
            // trying to hold 50.00 against a 100.00 balance. Exactly two must
            // win. Sequential calls would prove nothing here.
            $settled = $this->race->run(ProcessRace::holdArgs($u, 5000, 6), 6);

            $winners = count(array_filter($settled, fn ($r) => $r === 'ok'));
            $this->same(2, $winners, 'holds allowed (got: '.implode(',', $settled).')');

            $row = DB::table('wallet')->where('user_id', $u)->first();
            $this->same(0, (int) $row->balance_available, 'available');
            $this->same(10000, (int) $row->balance_held, 'held');

            $check = $this->wallet->reconcile($u);
            $this->same(true, $check['ok'], 'reconciled after race');
        });

        $this->check('ten buyers hammering one lot never lose a bid', function () {
            $lot = $this->makeLot();
            $this->lots->openLot($lot->id);

            // Ten REAL processes bidding valid increasing amounts at once.
            // Losing a race for a price is legitimate, so failures are counted
            // rather than asserted; what matters is that nothing is LOST.
            $this->race->run(
                ProcessRace::bidArgs($lot->id, 10000, 2500, 10),
                10
            );

            $final = Lot::where('id', $lot->id)->first();
            $bids = DB::table('bid')->where('lot_id', $lot->id)->get();

            $highest = max(array_map(fn ($b) => (int) $b->amount, $bids->all()));
            $this->same($highest, (int) $final->current_bid, 'current_bid is the top bid');
            $this->same(count($bids), (int) $final->bid_count, 'bid_count matches rows');

            $winner = $bids->firstWhere('amount', $highest);
            $this->same($winner->bidder_id, $final->highest_bidder_id, 'leader matches top bid');

            $accepted = $bids->filter(fn ($b) => $b->status === 'ACCEPTED');
            $this->same(1, $accepted->count(), 'ACCEPTED bid count');
            $this->same($highest, (int) $accepted->first()->amount, 'ACCEPTED is the top bid');
        });
    }

    // ------------------------------------------------------------------ helpers

    private function makeLot(array $overrides = []): Lot
    {
        return Lot::create(array_merge([
            'id' => 'lot_'.Str::random(16),
            'vin' => 'VIN-'.$this->run,
            'year' => 2016,
            'make' => 'Honda',
            'model' => 'Accord',
            'city' => 'Toronto',
            'photos' => [],
            'starting_bid' => 10000,
            'reserve_price' => 50000,
            'bid_increment' => 2500,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'status' => 'UPCOMING',
            'currency_code' => 'usd',
        ], $overrides));
    }

    /** Push a lot's close time into the past so finalization can run. */
    private function expire(string $lotId): void
    {
        DB::table('lot')->where('id', $lotId)->update(['ends_at' => now()->subSecond()]);
    }

    private function check(string $name, callable $fn): void
    {
        try {
            $fn();
            $this->passed++;
            echo "  PASS  {$name}\n";
        } catch (\Throwable $e) {
            $this->failed++;
            $msg = explode("\n", $e->getMessage())[0];
            $this->failures[] = "{$name}: {$msg}";
            echo "  FAIL  {$name}\n";
            echo "        {$msg}\n";
        }
    }

    /** Assert a call throws, and that the message contains `needle`. */
    private function throwsCode(callable $fn, string $needle): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $actual = $e->getMessage();
            $this->assertTrue(
                str_contains($actual, $needle),
                sprintf('expected error containing "%s", got "%s"', $needle, $actual)
            );

            return;
        }
        throw new RuntimeException("expected a throw containing \"{$needle}\", but it succeeded");
    }

    private function same(mixed $expected, mixed $actual, string $what): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                sprintf('%s: expected %s, got %s', $what, json_encode($expected), json_encode($actual))
            );
        }
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    private function report(): void
    {
        echo "\n".str_repeat('=', 56)."\n";
        echo sprintf("  %d passed, %d failed, %d total\n", $this->passed, $this->failed, $this->passed + $this->failed);
        echo str_repeat('=', 56)."\n";

        if ($this->failures) {
            echo "\nFailures:\n";
            foreach ($this->failures as $f) {
                echo "  - {$f}\n";
            }
        }
    }
}