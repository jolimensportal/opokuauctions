<?php

namespace App\Enums;

/**
 * Why there is no quote.
 *
 * Every case is a refusal to invent a number, not a failure. They exist so the
 * page can say something a customer can act on: "we cannot price a 3 kg parcel
 * from Newark on courier pickup" is useful, and a blank card is not.
 */
enum QuoteUnavailableReason: string
{
    case UnreadablePostcode = 'unreadable_postcode';
    case UnmappedPostcode = 'unmapped_postcode';
    case MissingDimensions = 'missing_dimensions';
    case UnpricedLane = 'unpriced_lane';
    case ExpiredRate = 'expired_rate';

    /**
     * Sentence shown to the sender.
     *
     * @param  array<string, string>  $context
     */
    public function message(array $context = []): string
    {
        return match ($this) {
            self::UnreadablePostcode => 'That postcode is not a format we recognise for that country.',
            self::UnmappedPostcode => sprintf(
                'We do not ship from %s yet.',
                $context['postcode'] ?? 'that postcode',
            ),
            self::MissingDimensions => 'We need the length, width and height to price this, because air freight is charged on the space it takes.',
            self::UnpricedLane => $context['service'] ?? null
                ? 'We do not have a price for that service on that lane yet.'
                : ($context['origin'] ?? null
                    ? sprintf('We do not ship from %s yet.', $context['origin'])
                    : 'We do not have a price for that lane yet.'),
            self::ExpiredRate => 'Our price for that lane has expired and is being reviewed.',
        };
    }
}
