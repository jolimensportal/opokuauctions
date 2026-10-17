<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Anti-sniping: a bid arriving inside the window pushes the close time out.
 *
 * Without this, the last two minutes of an auction are decided by whoever has
 * the faster connection, which is how a buyer ends up winning a lot they were
 * never really outbid on.
 *
 * Its own file because PSR-4 maps exactly one class per file: while this class
 * shared LotsService.php it worked whenever LotsService was the entry point and
 * failed with "class not found" the moment a view referenced it directly.
 */
final class AntiSnipe
{
    /** How close to the close a bid must land to trigger an extension. */
    public const WINDOW_MS = 2 * 60 * 1000;

    /** How far the close moves when a late bid lands. */
    public const EXTENSION_MS = 2 * 60 * 1000;

    /**
     * The same window in whole minutes.
     *
     * Exists so bidder-facing copy can state the rule without restating 120000
     * as the word "two". Hardcoded prose next to a constant is how a published
     * promise quietly stops matching the code that enforces it.
     */
    public const WINDOW_MINUTES = 2;
}