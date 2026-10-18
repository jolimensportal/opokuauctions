<?php

namespace App\Services;

/**
 * Normalising a sender's postcode.
 *
 * Pure and static: no database, no framework, no clock. It is the first thing
 * that runs on every quote, and a wrong answer here routes a sender to the wrong
 * rate band and therefore to the wrong price.
 *
 * The two countries are handled separately on purpose. A US ZIP is five digits
 * and a Canadian postal code alternates letter-digit three times; treating them
 * as the same shape means reading `M5V` as a number.
 *
 * Anything that cannot be normalised returns null. Nothing is guessed: a
 * four-digit string is not a US ZIP with a leading zero, it is a typo, and
 * inventing the zero would silently route the parcel.
 */
final class Postcode
{
    public static function normalise(string $input, string $country): ?string
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            return null;
        }

        return match (strtolower($country)) {
            'us' => self::us($trimmed),
            'ca' => self::canada($trimmed),
            default => null,
        };
    }

    private static function us(string $input): ?string
    {
        // ZIP+4 collapses to its ZIP: the band is priced from the ZIP.
        $zip = str_contains($input, '-')
            ? explode('-', $input, 2)[0]
            : $input;

        $zip = preg_replace('/\s+/', '', $zip) ?? '';

        if (preg_match('/^\d{5}$/', $zip) === 1) {
            return $zip;
        }

        // "703" is a person typing four digits, not a ZIP missing its leading
        // zero. Refusing is right; padding would move the parcel for free.
        return null;
    }

    private static function canada(string $input): ?string
    {
        $compact = strtoupper(preg_replace('/[\s\-.]+/', '', $input) ?? '');

        if (preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $compact) === 1) {
            return $compact;
        }

        return null;
    }
}