<?php

namespace App\Data;

use App\Enums\QuoteUnavailableReason;
use App\Models\RateCardRow;

/**
 * One resolved price, or the reason there is none.
 *
 * The reason is a value rather than a null, because "we cannot price this from
 * Newark on courier pickup" is something a customer and an operator can both
 * act on, and a blank card is neither.
 */
final readonly class ResolvedRate
{
    /**
     * @param  array<string, string>  $context
     */
    private function __construct(
        public ?RateCardRow $row,
        public ?QuoteUnavailableReason $reason,
        public array $context,
    ) {}

    public static function found(RateCardRow $row): self
    {
        return new self($row, null, []);
    }

    public static function refused(QuoteUnavailableReason $reason, array $context = []): self
    {
        return new self(null, $reason, $context);
    }

    public function isAvailable(): bool
    {
        return $this->row !== null;
    }

    public function message(): string
    {
        return $this->reason?->message($this->context) ?? '';
    }
}