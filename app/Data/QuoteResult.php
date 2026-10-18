<?php

namespace App\Data;

use App\Enums\QuoteUnavailableReason;

/**
 * A quote, or the reason there is none.
 *
 * Never both and never neither: a caller can always ask why, which is what
 * makes an empty price card actionable instead of merely broken.
 */
final readonly class QuoteResult
{
    /**
     * @param  array<string, string>  $context
     */
    private function __construct(
        public ?FreightQuote $quote,
        public ?QuoteUnavailableReason $reason,
        public array $context,
    ) {}

    public static function quoted(FreightQuote $quote): self
    {
        return new self($quote, null, []);
    }

    public static function refused(QuoteUnavailableReason $reason, array $context = []): self
    {
        return new self(null, $reason, $context);
    }

    public function isAvailable(): bool
    {
        return $this->quote !== null;
    }

    public function message(): string
    {
        return $this->reason?->message($this->context) ?? '';
    }
}