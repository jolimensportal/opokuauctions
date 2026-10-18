<?php

namespace App\Enums;

/**
 * How urgent an open exception is.
 *
 * Ordered by the queue's sort, so the weight must stay strictly monotonic:
 * Critical is heaviest, Low is lightest. Nothing sets this by hand — every
 * value is derived from a fact about the underlying row, because a manually
 * entered priority rots the moment anyone learns more and nobody goes back to
 * correct it.
 */
enum Severity: int
{
    /** Real money spent with no proof it was spent. Nothing outranks it. */
    case Critical = 4;

    /** Blocking work: a container cannot ship, be released, or be divided. */
    case High = 3;

    /** A real fault that has a workaround and no deadline today. */
    case Medium = 2;

    /** Worth noticing. Nobody is blocked. */
    case Low = 1;

    /**
     * Numeric rank, so the queue can sort descending with one comparison.
     */
    public function weight(): int
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
        };
    }
}