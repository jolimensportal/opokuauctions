<?php

namespace Tests\Feature;

use App\Models\Lot;
use App\Services\FacetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Facet counts are the trust surface of the browse experience.
 *
 * The rule under test: the numbers a buyer filters by must add up. A filter
 * panel that double-counts a lot, or hides one entirely, tells a buyer their
 * choice is worth making and then lies about it.
 */
class FacetServiceTest extends TestCase
{
    use RefreshDatabase;

    private function lot(array $overrides = []): Lot
    {
        // Make, model, year and odometer live in vehicle_detail now, so this
        // pulls them out of the overrides and writes them there. Passing them
        // to lot would be silently ignored and the facets would count nothing.
        $detail = array_intersect_key($overrides, array_flip([
            'make', 'model', 'year', 'odometer', 'trim', 'vin', 'damage_primary',
        ]));

        $lotOverrides = array_diff_key($overrides, $detail);

        $lot = Lot::factory()->create([
            'id' => 'lot_facet_'.substr(md5(json_encode($overrides).microtime()), 0, 10),
            'title' => ($detail['make'] ?? 'Toyota').' '.($detail['model'] ?? 'Corolla'),
            'condition' => 'clean_title',
            'city' => 'Detroit',
            'current_bid' => 100_000,
            'reserve_price' => 0,
            'ends_at' => now()->addDays(3),
            ...$lotOverrides,
        ]);

        $lot->vehicleDetail->update([
            'make' => $detail['make'] ?? 'Toyota',
            'model' => $detail['model'] ?? 'Corolla',
            'year' => $detail['year'] ?? 2020,
            'odometer' => $detail['odometer'] ?? null,
        ]);

        return $lot->fresh();
    }

    public function test_only_open_lots_are_counted(): void
    {
        $this->lot(['status' => 'LIVE']);
        $this->lot(['status' => 'UPCOMING']);
        $this->lot(['status' => 'SOLD']);
        $this->lot(['status' => 'CANCELLED']);

        $this->assertSame(2, (new FacetService)->totalOpen());
    }

    /** A LIVE lot whose close time has passed must not be advertised. */
    public function test_a_live_lot_that_has_already_closed_is_not_counted(): void
    {
        $this->lot(['status' => 'LIVE', 'ends_at' => now()->subMinute()]);

        $this->assertSame(0, (new FacetService)->totalOpen());
    }

    public function test_make_counts_group_and_sort_by_size(): void
    {
        $this->lot(['make' => 'Ford', 'status' => 'LIVE']);
        $this->lot(['make' => 'Ford', 'status' => 'LIVE']);
        $this->lot(['make' => 'Kia', 'status' => 'LIVE']);

        $counts = (new FacetService)->byMake()->keyBy('value');

        $this->assertSame(2, $counts['Ford']->count);
        $this->assertSame(1, $counts['Kia']->count);
        $this->assertSame('Ford', (new FacetService)->byMake()->first()->value);
    }

    /**
     * The bug this guards: the bands previously derived their upper bound from
     * their own index, so two bands queried the same window and the counts did
     * not sum to the total.
     */
    public function test_closing_bands_partition_the_open_lots_exactly(): void
    {
        $this->lot(['ends_at' => now()->addMinutes(30)]);   // Closing now
        $this->lot(['ends_at' => now()->addHours(5)]);      // Later today
        $this->lot(['ends_at' => now()->addDays(3)]);       // This week
        $this->lot(['ends_at' => now()->addDays(30)]);      // Later

        $facets = new FacetService;
        $bands = $facets->byClosingSoon();

        $sum = $bands->sum('count');

        $this->assertSame(
            $facets->totalOpen(),
            $sum,
            'Closing bands must account for every open lot exactly once.'
        );

        $byValue = $bands->keyBy('value');
        $this->assertSame(1, $byValue['Closing now']->count);
        $this->assertSame(1, $byValue['Later today']->count);
        $this->assertSame(1, $byValue['This week']->count);
        $this->assertSame(1, $byValue['Later']->count);
    }

    public function test_price_bands_partition_the_open_lots_exactly(): void
    {
        $this->lot(['current_bid' => 50_000]);      // under 1,000
        $this->lot(['current_bid' => 150_000]);     // 1,000 - 2,500
        $this->lot(['current_bid' => 600_000]);     // 5,000 - 10,000
        $this->lot(['current_bid' => 4_000_000]);    // above 10,000

        $facets = new FacetService;
        $sum = array_sum(array_column($facets->byPriceBand(), 'count'));

        $this->assertSame($facets->totalOpen(), $sum);
    }

    public function test_price_bands_do_not_double_count_the_boundary(): void
    {
        // Exactly 1,000 USD must land in one band, not two. Half-open intervals
        // are the only way this holds for every value.
        $this->lot(['current_bid' => 100_000]);

        $bands = collect((new FacetService)->byPriceBand())->filter(fn ($b) => $b['count'] > 0);

        $this->assertCount(1, $bands);
    }

    public function test_year_bands_partition_the_open_lots_exactly(): void
    {
        $this->lot(['year' => 2025]);
        $this->lot(['year' => 2020]);
        $this->lot(['year' => 2015]);
        $this->lot(['year' => 2008]);
        $this->lot(['year' => 1998]);

        $facets = new FacetService;

        $this->assertSame($facets->totalOpen(), $facets->byYear()->sum('count'));
    }

    public function test_mileage_bands_ignore_lots_with_no_recorded_odometer(): void
    {
        $this->lot(['odometer' => 30_000]);
        $this->lot(['odometer' => null]);

        $facets = new FacetService;
        $counted = $facets->byMileage()->sum('count');

        $this->assertSame(1, $counted, 'A lot with no odometer cannot fall in a mileage band.');
    }

    public function test_yard_and_make_counts_are_distinct_values_not_lot_counts(): void
    {
        $this->lot(['make' => 'Ford', 'city' => 'Detroit']);
        $this->lot(['make' => 'Ford', 'city' => 'Detroit']);
        $this->lot(['make' => 'Kia', 'city' => 'Chicago']);

        $facets = new FacetService;

        $this->assertSame(2, $facets->yardCount());
        $this->assertSame(2, $facets->makeCount());
        $this->assertSame(3, $facets->totalOpen());
    }

    public function test_an_empty_facet_reports_zero_rather_than_being_hidden(): void
    {
        // Demo stock does not cover every band, and the panel must say so.
        $this->lot(['current_bid' => 150_000]);

        $empty = collect((new FacetService)->byPriceBand())
            ->first(fn ($b) => $b['count'] === 0);

        $this->assertNotNull($empty);
    }
}