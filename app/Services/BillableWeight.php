<?php

namespace App\Services;

/**
 * Billable weight, and what it costs.
 *
 * Billable weight is whichever is greater: the weight on the scale, or the
 * volume the thing occupies. A 2 kg box of screws and a 2 kg box of pillows
 * cost the same to move, and only the second one is worth charging for the
 * space. Skipping the volumetric figure is how light-and-bulky destroys margin.
 *
 * Everything is an integer. Floating point gives 1800 x 0.32 = 576.0000000001,
 * a cent off a real price, and the error lands wherever the binary
 * representation falls rather than where the arithmetic says.
 *
 * Dimensions are whole centimetres. Air freight is quoted on whole-centimetre
 * boxes; a dimension of 12.5 cm stored as 13 is an invented decimal point.
 */
final class BillableWeight
{
    private const GRAMS_PER_KILO = 1000;

    /**
     * Billable weight in whole grams, or null if the inputs cannot be trusted.
     *
     * @param  int  $divisor  volumetric divisor: 2000 for air, 5000 for sea
     */
    public static function grams(float $weightKg, int $lengthCm, int $widthCm, int $heightCm, int $divisor): ?int
    {
        if ($weightKg < 0 || $divisor <= 0) {
            return null;
        }

        // A zero or negative dimension is unknown, not flat.
        if ($lengthCm <= 0 || $widthCm <= 0 || $heightCm <= 0) {
            return null;
        }

        $actualGrams = (int) round($weightKg * self::GRAMS_PER_KILO);

        $cubicCm = $lengthCm * $widthCm * $heightCm;
        $volumetricGrams = self::roundHalfUp($cubicCm * self::GRAMS_PER_KILO, $divisor);

        return max($actualGrams, $volumetricGrams);
    }

    /**
     * Cents for a billable weight, from a per-kilo rate.
     *
     * @param  int  $billableGrams
     * @param  int  $perKgCents
     */
    public static function cents(int $billableGrams, int $perKgCents): int
    {
        if ($billableGrams <= 0 || $perKgCents < 0) {
            return 0;
        }

        return self::roundHalfUp($billableGrams * $perKgCents, self::GRAMS_PER_KILO);
    }

    /** Apply a minimum charge, which is a floor and never a price. */
    public static function floor(int $cents, int $minimumCents): int
    {
        return max($cents, $minimumCents);
    }

    /**
     * Integer division rounded half away from zero.
     *
     * Both arguments are positive on this path. The + divisor/2 then truncate
     * is round-half-up without touching a float.
     */
    private static function roundHalfUp(int $numerator, int $divisor): int
    {
        if ($divisor <= 0) {
            return 0;
        }

        return intdiv($numerator + intdiv($divisor, 2), $divisor);
    }
}