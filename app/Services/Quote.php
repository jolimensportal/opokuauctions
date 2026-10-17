<?php

namespace App\Services;

/**
 * One lot's freight, priced.
 *
 * A value object, not a model. Nothing about a quote is ever stored: it is
 * recomputed from the lot's weight and the live rate every time it is asked
 * for, because a stored quote is a promise to honour a price that has since
 * been corrected.
 *
 * Money is integer cents. The weight is a float because a car does not weigh a
 * round number of kilos and pretending otherwise would be its own kind of lie.
 */
final readonly class Quote
{
    public function __construct(
        public float $weightKg,
        public int $freightCents,
        public int $insuranceCents,
        public int $pickupCents,
        public int $totalCents,
        public string $currencyCode,
        public bool $available,
    ) {}

    /**
     * The wire format.
     *
     * The key order and the key set are both fixed: the lot page reads these by
     * name and branches on `available`, so a renamed or added key changes what
     * the buyer is shown without any test noticing.
     *
     * @return array{weightKg: float, freightCents: int, insuranceCents: int, pickupCents: int, totalCents: int, currencyCode: string, available: bool}
     */
    public function toArray(): array
    {
        return [
            'weightKg' => $this->weightKg,
            'freightCents' => $this->freightCents,
            'insuranceCents' => $this->insuranceCents,
            'pickupCents' => $this->pickupCents,
            'totalCents' => $this->totalCents,
            'currencyCode' => $this->currencyCode,
            'available' => $this->available,
        ];
    }

    /**
     * The answer for a lot we cannot price.
     *
     * Same seven keys, all zero, `available` false. Not an exception and not an
     * error status: the toggle on the lot page is asked on every click, and a
     * buyer who cannot be quoted needs the page to say so rather than to break.
     *
     * @return array{weightKg: float, freightCents: int, insuranceCents: int, pickupCents: int, totalCents: int, currencyCode: string, available: bool}
     */
    public static function unavailablePayload(): array
    {
        return [
            'weightKg' => 0.0,
            'freightCents' => 0,
            'insuranceCents' => 0,
            'pickupCents' => 0,
            'totalCents' => 0,
            'currencyCode' => 'usd',
            'available' => false,
        ];
    }
}