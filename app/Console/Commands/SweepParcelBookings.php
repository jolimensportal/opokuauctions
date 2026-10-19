<?php

namespace App\Console\Commands;

use App\Services\ParcelBookingService;
use Illuminate\Console\Command;

class SweepParcelBookings extends Command
{
    protected $signature = 'parcel:sweep-bookings';

    protected $description = 'Expire timed-out payment links and abandon bookings nobody replied to';

    public function handle(ParcelBookingService $bookings): int
    {
        $result = $bookings->sweep();

        // Reported separately on purpose. A timed-out link and an unanswered
        // booking are different events, and a single blended number would hide
        // which one is actually happening.
        $this->info("expired {$result['expired']}, abandoned {$result['abandoned']}");

        return self::SUCCESS;
    }
}
