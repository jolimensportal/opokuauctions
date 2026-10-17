<?php

namespace App\Console\Commands;

use App\Spike\SpikeCheck;
use Illuminate\Console\Command;

/**
 * Runs the 21-check money + auction suite.
 *
 * The twin of `spikes/medusa/apps/backend/src/scripts/spike-check.ts`, run with
 * `medusa exec`. Same checks, same order, same error codes.
 */
class SpikeRun extends Command
{
    protected $signature = 'spike:run';

    protected $description = 'Run the OpokuAuctions money and auction spike checks';

    public function handle(SpikeCheck $check): int
    {
        return $check->run();
    }
}