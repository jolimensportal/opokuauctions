<?php

use App\Services\CurbWeightLookup;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Weigh the lots that predate the curb weight column.
 *
 * New lots are weighed as they are created. This exists for a database that
 * already has inventory: those rows will never be created again, so nothing but
 * an explicit pass will ever fill them, and every one of them is a lot page with
 * no freight figure on it.
 *
 * Reports what it could not weigh on purpose. A car we have no weight for is
 * unquotable, and that is a list somebody has to work through, not something to
 * discover later from a blank page.
 */
Artisan::command('lots:weigh', function (CurbWeightLookup $weights) {
    $result = $weights->backfill();

    $this->info(sprintf(
        'Weighed %d lot(s); %d still have no known weight and cannot be quoted.',
        $result['filled'],
        $result['unweighable'],
    ));
})->purpose('Fill in curb weights for lots that have none');