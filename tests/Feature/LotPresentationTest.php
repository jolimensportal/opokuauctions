<?php

namespace Tests\Feature;

use App\Models\Lot;
use App\Services\AntiSnipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two Lot helpers that decide what a buyer is shown.
 *
 * These look like view sugar, but they are not: minimumNextBid() is also the
 * amount the server will accept, and isAcceptingBids() is the same test the
 * bid validator applies. If either drifts from the page, a buyer is told they
 * can bid an amount that will be rejected, or shown a bid box on a closed lot.
 */
class LotPresentationTest extends TestCase
{
    use RefreshDatabase;

    private function lot(array $overrides = []): Lot
    {
        // Unique per call: a test that builds several lots in a loop would
        // otherwise collide on the primary key.
        $n = (string) substr(md5(json_encode($overrides).microtime()), 0, 10);

        $defaults = [
            'id' => 'lot_test_'.$n,
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => 'Toyota Corolla',
            'city' => 'Detroit',
            'condition' => 'clean_title',
            'currency_code' => 'usd',
            'starting_bid' => 100_000,
            'reserve_price' => 150_000,
            'bid_increment' => 5_000,
            'status' => 'LIVE',
            'current_bid' => 100_000,
            'bid_count' => 0,
            'extensions' => 0,
            // Both are NOT NULL in the schema. Defaulted here so each test only
            // has to state the column it is actually about.
            'starts_at' => now()->subDay(),
            'photos' => [],
            'ends_at' => now()->addHour(),
        ];

        return Lot::create(array_merge($defaults, $overrides));
    }

    public function test_first_bid_on_a_lot_must_meet_the_starting_price(): void
    {
        $lot = $this->lot(['bid_count' => 0, 'current_bid' => 100_000]);

        $this->assertSame(100_000, $lot->minimumNextBid());
    }

    public function test_subsequent_bids_must_clear_the_increment(): void
    {
        $lot = $this->lot(['bid_count' => 4, 'current_bid' => 215_000, 'bid_increment' => 5_000]);

        $this->assertSame(220_000, $lot->minimumNextBid());
    }

    public function test_a_live_lot_with_a_future_end_time_accepts_bids(): void
    {
        $this->assertTrue($this->lot()->isAcceptingBids());
    }

    /** A lot can be LIVE and still be closed: the close job has not run yet. */
    public function test_a_live_lot_whose_end_time_has_passed_does_not_accept_bids(): void
    {
        $lot = $this->lot(['ends_at' => now()->subMinute()]);

        $this->assertFalse($lot->isAcceptingBids());
    }

    public function test_an_upcoming_lot_does_not_accept_bids(): void
    {
        $this->assertFalse($this->lot(['status' => 'UPCOMING'])->isAcceptingBids());
    }

    public function test_countdown_is_expressed_in_human_units_not_raw_minutes(): void
    {
        // The bug this guards: a two-day auction rendered as "Closes in 2975
        // minutes", which is both unreadable and stale the moment it renders.
        $twoDays = $this->lot(['ends_at' => now()->addDays(2)->addHours(4)]);

        $this->assertSame('2 days 4 hr', $twoDays->timeUntilClose());
    }

    /**
     * Rounding days and hours independently produces "4 days 24 hr" for a lot
     * with 4 days 23 hours 59 minutes left. Decomposing total hours instead
     * makes an hour remainder of 24 unrepresentable.
     */
    public function test_countdown_never_reports_a_24_hour_remainder(): void
    {
        foreach ([4, 9, 23, 47] as $hoursOut) {
            $lot = $this->lot([
                'ends_at' => now()->addDays(4)->addHours($hoursOut)->addMinutes(59),
            ]);

            $this->assertDoesNotMatchRegularExpression(
                '/24 hr/',
                $lot->timeUntilClose(),
                sprintf('A lot %d days out rendered an impossible 24 hr remainder.', $hoursOut),
            );
        }
    }

    public function test_countdown_drops_minutes_once_the_lot_is_more_than_six_hours_out(): void
    {
        // "19 hr 51 min" is noise on the cards; the buyer needs to know whether
        // it is today or tomorrow, not the minute hand.
        $later = $this->lot(['ends_at' => now()->addHours(19)->addMinutes(51)]);

        $this->assertSame('20 hr', $later->timeUntilClose());
    }

    public function test_countdown_keeps_minutes_for_a_lot_closing_within_six_hours(): void
    {
        $soon = $this->lot(['ends_at' => now()->addHours(3)->addMinutes(20)]);

        $this->assertSame('3 hr 20 min', $soon->timeUntilClose());
    }

    public function test_countdown_uses_minutes_for_lots_closing_within_the_hour(): void
    {
        $soon = $this->lot(['ends_at' => now()->addMinutes(45)]);

        $this->assertSame('45 min', $soon->timeUntilClose());
    }

    public function test_countdown_reports_a_closed_lot_as_closing(): void
    {
        $past = $this->lot(['ends_at' => now()->subMinute()]);

        $this->assertSame('Closing', $past->timeUntilClose());
    }

    public function test_primary_photo_is_null_when_a_lot_has_no_imagery(): void
    {
        $this->assertNull($this->lot(['photos' => []])->primaryPhoto());
    }

    public function test_primary_photo_returns_the_first_photo(): void
    {
        $lot = $this->lot(['photos' => ['https://example.test/a.jpg', 'https://example.test/b.jpg']]);

        $this->assertSame('https://example.test/a.jpg', $lot->primaryPhoto());
    }

    /**
     * The lot page tells bidders how late a bid can be and still extend the
     * auction. That sentence is a promise about how their money behaves, so the
     * minutes in the copy are read from the constant rather than restated in
     * prose, and this asserts the two cannot drift.
     */
    public function test_the_anti_snipe_window_stated_to_bidders_matches_the_enforced_window(): void
    {
        $this->assertSame(
            AntiSnipe::WINDOW_MS,
            AntiSnipe::WINDOW_MINUTES * 60 * 1000,
            'The published anti-snipe window no longer matches the one enforced.',
        );
    }

    /**
     * AntiSnipe lived inside LotsService.php, which PSR-4 cannot autoload, so
     * the lot page crashed with "class not found" the moment it referenced the
     * constants directly.
     */
    public function test_anti_snipe_is_autoloadable_on_its_own(): void
    {
        $this->assertTrue(class_exists(AntiSnipe::class));
        $this->assertSame(
            realpath(__DIR__.'/../../app/Services/AntiSnipe.php'),
            (new \ReflectionClass(AntiSnipe::class))->getFileName(),
        );
    }
}