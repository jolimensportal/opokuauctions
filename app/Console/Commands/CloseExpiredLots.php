<?php

namespace App\Console\Commands;

use App\Services\LotsService;
use Illuminate\Console\Command;
use RuntimeException;

class CloseExpiredLots extends Command
{
    protected $signature = 'lots:close-expired {--limit=200 : How many expired lots to close in one pass}';

    protected $description = 'Close auctions whose time is up: sold when the reserve is met, otherwise not met';

    public function handle(LotsService $lots): int
    {
        $expired = $lots->findExpiredLiveLots((int) $this->option('limit'));

        $sold = 0;
        $notMet = 0;
        $needsReview = [];

        foreach ($expired as $lotId) {
            try {
                $result = $lots->finalizeLot($lotId);
            } catch (RuntimeException $exception) {
                // A lot carrying a reserve-met figure with no recorded bidder
                // is data a person has to look at. It stays open rather than
                // being closed as a sale nobody made.
                $needsReview[] = "{$lotId}: {$exception->getMessage()}";

                continue;
            }

            if (($result['status'] ?? null) === 'SOLD') {
                $sold++;
            } else {
                $notMet++;
            }
        }

        $this->info('sold '.$sold.', reserve not met '.$notMet.', needs review '.count($needsReview));

        foreach ($needsReview as $line) {
            $this->warn($line);
        }

        return self::SUCCESS;
    }
}
