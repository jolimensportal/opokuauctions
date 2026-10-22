<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Lot;
use App\Models\ParcelBooking;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Gives the demo buyer something to be waiting for.
 *
 * The demo container is real and its three cars are real rows, but nobody had
 * won any of them, so the customer portal showed a buyer with no freight at
 * all — the emptiest possible version of the page, which says nothing about
 * whether it works.
 *
 * One of the three cars is the buyer's, and it is paid in full, because a car
 * that is still owed money is a different story. The other two stay unowned:
 * a shared container carries other people's cars too, and the portal must not
 * show them.
 */
class DemoBuyerFreightSeeder extends Seeder
{
    public function run(): void
    {
        $buyer = User::query()->where('role', Role::Buyer->value)->first();

        if ($buyer === null) {
            return;
        }

        Lot::query()
            ->where('id', 'lot_demo_02')
            ->update([
                'highest_bidder_id' => $buyer->id,
                'balance_due_usd' => 0,
                'balance_settled_at' => now(),
            ]);

        // The oldest parcel still waiting for payment becomes the buyer's, so
        // the portal has a parcel on it as well as a car. It is expired, which
        // is the honest state of it.
        $booking = ParcelBooking::query()
            ->whereNull('user_id')
            ->where('status', 'payment_pending')
            ->orderBy('created_at')
            ->first();

        $booking?->update(['user_id' => $buyer->id]);
    }
}
