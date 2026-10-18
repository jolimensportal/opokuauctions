<?php

namespace Tests\Unit;

use App\Services\BillableWeight;
use PHPUnit\Framework\TestCase;

/**
 * Billable weight is max(actual, volumetric), in whole grams.
 *
 * Every figure here is an integer on purpose. Floating point gives
 * 1800 x 0.32 = 576.0000000001, which is a cent off a price a customer is
 * about to pay, and the rounding lands wherever the binary representation
 * happens to fall rather than where the arithmetic says it should.
 */
class BillableWeightTest extends TestCase
{
    public function test_actual_weight_wins_when_the_box_is_dense(): void
    {
        // 20 kg of bolts in a 30x30x30 box is 27,000 / 2000 = 13.5 kg
        // volumetric, so the real weight decides.
        $this->assertSame(20_000, BillableWeight::grams(20.0, 30, 30, 30, 2000));
    }

    public function test_volumetric_weight_wins_when_the_box_is_light(): void
    {
        // 2 kg of pillows in the same box: 13.5 kg volumetric beats 2 kg.
        $this->assertSame(13_500, BillableWeight::grams(2.0, 30, 30, 30, 2000));
    }

    public function test_the_divisor_changes_the_answer(): void
    {
        $air = BillableWeight::grams(2.0, 30, 30, 30, 2000);
        $sea = BillableWeight::grams(2.0, 30, 30, 30, 5000);

        // A larger divisor means more cubic centimetres make one kilo, so the
        // same box is worth LESS of the trip. Air is charged harder on volume
        // than sea, which is why its divisor is the smaller number: 2,000 cm³
        // per kilo against sea's 5,000.
        $this->assertLessThan($air, $sea);
        $this->assertSame(5_400, $sea);
    }

    public function test_volumetric_rounds_half_up(): void
    {
        // 10x10x10 cm = 1000 cubic cm at divisor 3000 = 0.3333 kg = 333g.
        $this->assertSame(333, BillableWeight::grams(0.0, 10, 10, 10, 3000));
    }

    public function test_cents_are_derived_from_whole_grams(): void
    {
        // 1800 kg at 32 cents/kg = 57,600 cents.
        $this->assertSame(57_600, BillableWeight::cents(1_800_000, 32));
    }

    public function test_cents_round_half_up_rather_than_truncating(): void
    {
        // 1 gram at 32 cents/kg is 0.032 cents; the nearest cent is 0.
        $this->assertSame(0, BillableWeight::cents(1, 32));

        // 16 grams at 32 cents/kg = 0.512 cents, which rounds to 1.
        $this->assertSame(1, BillableWeight::cents(16, 32));
    }

    public function test_a_minimum_floor_is_applied_after_the_rate(): void
    {
        // A 2 kg headlamp is worth 64 cents of freight, but the forwarder's
        // minimum for the consignment is 25,000.
        $this->assertSame(64, BillableWeight::cents(2_000, 32));
        $this->assertSame(25_000, BillableWeight::floor(64, 25_000));
        $this->assertSame(64, BillableWeight::floor(64, 0));
    }

    public function test_a_negative_weight_is_refused(): void
    {
        // A negative volume would produce a negative billable weight and a
        // credit. Better to be handed nothing than to owe money back.
        $this->assertNull(BillableWeight::grams(-5.0, 30, 30, 30, 2000));
    }

    public function test_a_zero_dimension_is_refused(): void
    {
        // A missing dimension is not a flat box; it is unknown.
        $this->assertNull(BillableWeight::grams(2.0, 30, 0, 30, 2000));
    }
}