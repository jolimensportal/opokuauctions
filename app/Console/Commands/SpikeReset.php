<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wipe the spike's own working data.
 *
 * Repeated `spike:run` invocations accumulate hundreds of lots and wallets in
 * the development database, which quietly changes query results and makes
 * assertions depend on run order. This exists so that never happens by
 * accident again.
 *
 * It only ever touches the four spike tables, and refuses to run unless the
 * database name looks like a spike database.
 */
class SpikeReset extends Command
{
    protected $signature = 'spike:reset {--force : Skip the confirmation}';

    protected $description = 'Clear spike working data from the development database';

    public function handle(): int
    {
        $database = config('database.connections.pgsql.database');

        if (! str_contains((string) $database, 'spike') && ! str_contains((string) $database, 'test')) {
            $this->error("Refusing: database '{$database}' does not look like a spike database.");

            return 1;
        }

        if (! $this->option('force') && ! $this->confirm('Delete all spike data in '.$database.'?', true)) {
            return 1;
        }

        // Children first; there are no FK constraints but order stays honest.
        DB::table('shipment_lot_statuses')->delete();
        DB::table('shipment_milestones')->delete();
        DB::table('shipment_lots')->delete();
        DB::table('shipments')->delete();
        DB::table('forwarders')->delete();
        DB::table('ledger_entry')->delete();
        DB::table('wallet')->delete();
        DB::table('bid')->delete();
        DB::table('lot')->delete();

        $this->info('Spike data cleared.');

        return 0;
    }
}
