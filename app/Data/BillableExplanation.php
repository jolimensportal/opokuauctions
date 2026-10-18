<?php

namespace App\Data;

/**
 * Why a parcel was charged the weight it was charged.
 *
 * This exists because the page explaining a price and the service computing it
 * were once two separate calculations, and when the rate row's divisor is 5000
 * for air the page still divided by 2000 — telling a customer their box bills
 * at 48 kg while charging them for 20. An explanation that disagrees with the
 * invoice is worse than no explanation, so the numbers are computed once, here,
 * and travel with the quote.
 */
final readonly class BillableExplanation
{
    private function __construct(
        public int $actualGrams,
        public int $volumetricGrams,
        public int $billableGrams,
        public int $divisor,
    ) {}

    public static function of(int $actualGrams, int $volumetricGrams, int $billableGrams, int $divisor): self
    {
        return new self($actualGrams, $volumetricGrams, $billableGrams, $divisor);
    }

    /**
     * True when the box, not the scale, set the price.
     *
     * This is the sentence that earns the fee: "your box bills at 48 kg of air
     * freight — more than the 20 kg on the scale."
     */
    public function isVolumetric(): bool
    {
        return $this->volumetricGrams > $this->actualGrams;
    }

    public function actualKg(): float
    {
        return $this->actualGrams / 1000;
    }

    public function billableKg(): float
    {
        return $this->billableGrams / 1000;
    }
}