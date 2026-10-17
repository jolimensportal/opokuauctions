<?php

namespace App\Console\Commands;

use App\Services\LotsService;
use App\Services\WalletService;
use Illuminate\Console\Command;

/**
 * Runs ONE money or bid operation, in its own process, and prints a single
 * word: ok, insufficient, low, or error.
 *
 * PHP has no promises, so the concurrency checks in SpikeCheck cannot prove
 * anything with sequential calls. This command exists so the parent can spawn
 * several real processes, hold them at a shared start barrier, and let them hit
 * the database at the same instant on separate connections. That is the only
 * way to prove a row lock is doing work.
 */
class RaceOnce extends Command
{
    protected $signature = 'spike:race
        {type : hold|release|capture|bid|deposit}
        {--user= : user id}
        {--amount= : amount in minor units}
        {--lot= : lot id}
        {--bidder= : bidder id}
        {--currency=usd}
        {--start-at= : unix timestamp with microseconds to begin at}';

    protected $description = 'Perform a single money or bid operation after a shared start barrier';

    public function handle(WalletService $wallet, LotsService $lots): int
    {
        $startAt = (float) ($this->option('start-at') ?: 0);
        if ($startAt > 0) {
            // Spin/sleep to the shared barrier. usleep has microsecond resolution,
            // which is enough to line several processes up within a millisecond.
            while (microtime(true) < $startAt) {
                usleep(200);
            }
        }

        $type = $this->argument('type');
        $currency = $this->option('currency');

        try {
            switch ($type) {
                case 'deposit':
                    $wallet->deposit((string) $this->option('user'), (int) $this->option('amount'), $currency);

                    break;

                case 'hold':
                    $wallet->hold((string) $this->option('user'), (int) $this->option('amount'), $currency, ['id' => 'race']);

                    break;

                case 'release':
                    $wallet->release((string) $this->option('user'), (int) $this->option('amount'), $currency, ['id' => 'race']);

                    break;

                case 'capture':
                    $wallet->capture((string) $this->option('user'), (int) $this->option('amount'), $currency, ['id' => 'race']);

                    break;

                case 'bid':
                    $lots->placeBid([
                        'lot_id' => (string) $this->option('lot'),
                        'bidder_id' => (string) $this->option('bidder'),
                        'bidder_email' => (string) $this->option('bidder').'@e.com',
                        'amount' => (int) $this->option('amount'),
                    ]);

                    break;

                default:
                    $this->line('error:unknown-type');

                    return 1;
            }

            $this->line('ok');

            return 0;
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $this->line(match (true) {
                str_contains($message, 'Insufficient funds') => 'insufficient',
                str_contains($message, 'already the highest') => 'self-bid',
                str_contains($message, 'at least') => 'low',
                str_contains($message, 'not accepting bids') => 'not-live',
                str_contains($message, 'ended') => 'ended',
                str_contains($message, 'positive integer') => 'invalid',
                default => 'error',
            });

            return 1;
        }
    }
}