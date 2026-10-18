<?php

namespace App\Data;

/**
 * A price, and the row that produced it.
 *
 * Lines rather than a single total, so a customer can see that part of the
 * price is the courier stop rather than guessing. The row id is recorded so a
 * price stays explainable after the rate behind it has moved on.
 */
final readonly class FreightQuote
{
    /**
     * @param  array<int, array{label: string, cents: int}>  $lines
     */
    private function __construct(
        public array $lines,
        public int $totalCents,
        public string $currencyCode,
        public ?string $rateCardRowId,
    ) {}

    /**
     * Build a quote from its lines. The total is their sum, never a separately
     * supplied figure, so the two can never disagree.
     *
     * @param  array<int, array{label: string, cents: int}>  $lines
     */
    public static function of(array $lines, string $currencyCode, ?string $rateCardRowId): self
    {
        return new self(
            $lines,
            array_sum(array_column($lines, 'cents')),
            $currencyCode,
            $rateCardRowId,
        );
    }
}