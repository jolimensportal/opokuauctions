<?php

namespace App\Data;

use App\Enums\AttentionKind;
use App\Enums\Severity;
use Carbon\CarbonImmutable;

/**
 * One open exception, as the queue sees it.
 *
 * Immutable on purpose. The console page, the dashboard badge and the nightly
 * digest all render the same item, and a mutable object would let one of them
 * change what the others display.
 *
 * An item carries no state of its own beyond its owner, because an item has no
 * row in the database. It exists while its query returns it, and stops existing
 * the moment the underlying condition is repaired.
 */
final readonly class AttentionItem
{
    public function __construct(
        public AttentionKind $kind,
        public Severity $severity,
        /** Stable human reference, e.g. 'CLT-0007'. Sortable and quotable. */
        public string $ref,
        /** Full sentence describing the problem. */
        public string $label,
        /** When the underlying condition first became true, as best known. */
        public CarbonImmutable $raisedAt,
        /** Assigned staff member, or null when nobody is available. */
        public ?string $ownerId,
        /** Where the operator goes to fix it. */
        public string $actionUrl,
    ) {}

    public function isUnassigned(): bool
    {
        return $this->ownerId === null;
    }

    /**
     * Whole days since this was raised. Whole days, not a float: the queue
     * sorts on this, and a fractional value would reorder near-ties on
     * whichever side of a rounding boundary the clock happened to fall.
     */
    public function ageInDays(CarbonImmutable $now): int
    {
        return (int) $this->raisedAt->diffInDays($now);
    }

    /**
     * Queue ordering: most severe first, then oldest first.
     *
     * Severity before age, because a Critical opened this morning outranks a
     * High open for a fortnight. Age within a severity, because two Critical
     * items differ in urgency by how long the money has been unaccounted for.
     */
    public static function compareForQueue(self $a, self $b): int
    {
        return $b->severity->weight() <=> $a->severity->weight()
            ?: $a->raisedAt->getTimestamp() <=> $b->raisedAt->getTimestamp();
    }
}