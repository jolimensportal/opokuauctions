<?php

namespace Tests\Unit;

use App\Services\Allocation;
use PHPUnit\Framework\TestCase;

/**
 * Splitting a container cost between the lots inside it.
 *
 * The invariant that matters is the last one: the shares must sum to the total.
 * Rounding shares independently is how a platform ends up inventing or losing
 * cents on every shipment, and the sum is what reconciles against the ledger.
 */
class AllocationTest extends TestCase
{
    public function test_a_single_lot_takes_the_whole_amount(): void
    {
        $shares = Allocation::split(50_500, [1.0]);

        $this->assertSame([50_500], $shares);
        $this->assertSame(50_500, array_sum($shares));
    }

    public function test_shares_are_proportional_to_weight(): void
    {
        // 1:2:1 split of 400 cents.
        $shares = Allocation::split(400, [1.0, 2.0, 1.0]);

        $this->assertSame(100, $shares[0]);
        $this->assertSame(200, $shares[1]);
        $this->assertSame(100, $shares[2]);
    }

    public function test_the_shares_always_sum_to_the_total(): void
    {
        foreach ([[1.0, 1.0, 1.0], [1.0, 5.2, 0.4, 100.0], [3.3, 7.7]] as $weights) {
            $total = 50_555;
            $shares = Allocation::split($total, $weights);

            $this->assertSame(
                $total,
                array_sum($shares),
                'Allocation must not lose or invent a cent.',
            );
        }
    }

    public function test_the_remainder_goes_to_the_largest_fractional_parts(): void
    {
        // 10 cents across 3 equal lots cannot be equal: 3/3/3 leaves 1.
        $shares = Allocation::split(10, [1.0, 1.0, 1.0]);

        $this->assertSame(10, array_sum($shares));
        $this->assertSame([4, 3, 3], $shares);
    }

    public function test_a_lot_with_no_weight_still_receives_a_share(): void
    {
        // A weightless lot is priced per-kilo as zero, so under a pure
        // proportional split it would pay nothing and the cost would land on
        // its neighbours. It still occupies space, so it gets the even share.
        $shares = Allocation::split(900, [1.0, 0.0]);

        $this->assertSame(900, array_sum($shares));
        $this->assertSame(450, $shares[1], 'A weightless lot must not pay nothing.');
    }

    public function test_every_weight_zero_still_divides_evenly(): void
    {
        $shares = Allocation::split(100, [0.0, 0.0]);

        $this->assertSame([50, 50], $shares);
    }

    public function test_an_empty_container_divides_nothing(): void
    {
        $this->assertSame([], Allocation::split(500, []));
    }
}