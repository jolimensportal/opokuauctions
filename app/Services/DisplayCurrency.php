<?php

namespace App\Services;

use Illuminate\Support\Facades\Session;

/**
 * What currency the buyer is looking at.
 *
 * Lots are priced in USD because that is what the forwarder invoices. Buyers
 * think in cedis. This class is the single place that decides which of the two
 * appears on screen, so a price can never be rendered in the wrong currency on
 * one page and the right one on another.
 *
 * The buyer's *chosen* display currency never changes what they owe. Settlement
 * is USD; the rate they pay is the one locked at deposit. This is presentation.
 */
final class DisplayCurrency
{
    public const USD = 'usd';
    public const GHS = 'ghs';

    private const SESSION_KEY = 'display_currency';

    public function __construct(private readonly FxService $fx) {}

    /** @return array<int, string> */
    public function options(): array
    {
        return [self::USD, self::GHS];
    }

    /** The buyer's current choice, defaulting to USD. */
    public function current(): string
    {
        $stored = Session::get(self::SESSION_KEY, self::USD);

        return in_array($stored, $this->options(), true) ? $stored : self::USD;
    }

    public function set(string $currency): string
    {
        $currency = strtolower($currency);

        if (! in_array($currency, $this->options(), true)) {
            $currency = self::USD;
        }

        Session::put(self::SESSION_KEY, $currency);

        return $currency;
    }

    /** Convert USD minor units into the buyer's chosen currency. */
    public function convert(int $minorUsd, ?string $into = null): int
    {
        $into = $into ?? $this->current();

        return $into === self::USD
            ? $minorUsd
            : $this->fx->convert($minorUsd, 'usd', $into);
    }

    /**
     * Format USD minor units for display in the buyer's currency.
     *
     * Cedi carries no minor unit on a receipt in practice, so it is rendered to
     * the whole cedi above a thousand and to two decimals below it: showing
     * "GHS 150,500.00" implies a precision nobody transacts in.
     */
    public function format(int $minorUsd, ?string $into = null): string
    {
        $currency = strtoupper($into ?? $this->current());
        $amount = $this->convert($minorUsd, $into) / 100;

        // Cedi above a thousand are not quoted in pesewas, so "GHS 57,943" is
        // the honest form. USD is different: a vehicle price is quoted to the
        // cent and dropping it reads as a rounding we performed, which is
        // exactly the doubt this page exists to remove.
        $decimals = $currency === 'GHS' && abs($amount) >= 1000 ? 0 : 2;

        return $currency.' '.number_format($amount, $decimals);
    }

    /** The short symbol used next to a price. */
    public function symbol(?string $into = null): string
    {
        return strtoupper($into ?? $this->current());
    }

    /** The live rate, so the page can say what it is converting at. */
    public function rateLabel(?string $into = null): string
    {
        $into = $into ?? $this->current();

        if ($into === self::USD) {
            return 'Prices in USD';
        }

        // 100 minor units is USD 1.00, not 100_000 which would be USD 1,000.
        return '1 USD = '.number_format($this->fx->convert(100, 'usd', $into) / 100, 2).' '.strtoupper($into);
    }

    /**
     * Both figures, for anywhere a buyer may want to sanity-check the cedi
     * against the invoice currency. Always derived from one conversion.
     *
     * @return array{usd:string, ghs:string}
     */
    public function both(int $minorUsd): array
    {
        return [
            'usd' => $this->format($minorUsd, self::USD),
            'ghs' => $this->format($minorUsd, self::GHS),
        ];
    }
}