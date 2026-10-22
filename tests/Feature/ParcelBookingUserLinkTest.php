<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\ParcelBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A parcel a signed-in buyer sent is theirs.
 *
 * The booking table had an email and no user, so a buyer who signed in and
 * sent a parcel had no way of finding it. Email is not a link: it is a
 * string somebody typed, and it can be wrong.
 */
class ParcelBookingUserLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bookings_table_can_hold_a_user(): void
    {
        $this->assertTrue(Schema::hasColumn('parcel_bookings', 'user_id'));
    }

    public function test_a_signed_in_buyer_owns_the_booking_they_make(): void
    {
        $buyer = User::factory()->create(['role' => Role::Buyer]);

        $booking = app(ParcelBookingService::class)->open(
            quoteInput: [
                'country' => 'us',
                'postcode' => '07030',
                'unit' => 'parcel',
                'weight_kg' => 20,
                'length_cm' => 60,
                'width_cm' => 40,
                'height_cm' => 40,
                'pickup' => false,
            ],
            quotedTotalCents: 2500,
            rateCardRowId: 'row-newark-air-standard',
            email: $buyer->email,
            userId: $buyer->id,
        );

        $this->assertSame($buyer->id, $booking->user_id);
        $this->assertTrue($booking->user->is($buyer));
    }

    public function test_a_guest_booking_has_no_user(): void
    {
        $booking = app(ParcelBookingService::class)->open(
            quoteInput: [
                'country' => 'us',
                'postcode' => '07030',
                'unit' => 'parcel',
                'weight_kg' => 20,
            ],
            quotedTotalCents: 2500,
            rateCardRowId: 'row-newark-air-standard',
            email: 'guest@example.test',
        );

        $this->assertNull($booking->user_id);
        $this->assertNull($booking->user);
    }
}
