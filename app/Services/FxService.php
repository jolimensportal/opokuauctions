<?php

namespace App\Services;

use App\Models\FxQuote;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Exchange rates, and the promise attached to them.
 *
 * The promise: a buyer is shown one rate, quoted at the moment they commit, and
 * that rate is what they pay. It is written to fx_quotes and never recomputed
 * for that lot. The platform carries the exposure between the buyer's rate and
 * whatever the forwarder charges, which is a business decision and not something
 * to push onto a buyer who will notice.
 *
 * Rates come from configuration, not an HTTP call. A live provider plugs in
 * behind currentRate() later; nothing else in the system needs to change,
 * because the only method that reads the outside world is that one.
 *
 * Every conversion is integer arithmetic on minor units. A float would make
 * "GHS 12,000.00" not sum to the same number twice, which is unacceptable in a
 * ledger-backed product.
 */
final class FxService
{
    /** Minor units per whole unit, by currency. USD and GHS both use 2. */
    private const MINOR = [
        'usd' => 2,
        'ghs' => 2,
        'cad' => 2,
    ];

    /**
     * Current rate for a pair, from configuration.
     *
     * A rate of 15.0000 is stored as 150000/10000 so the conversion is exact:
     * (1000 USD minor * 150000) / 10000 = 15000 = GHS 150.00.
     *
     * @return array{numerator:int, denominator:int}
     */
    public function currentRate(string $base = 'usd', string $quote = 'ghs'): array
    {
        $base = strtolower($base);
        $quote = strtolower($quote);

        if ($base === $quote) {
            return ['numerator' => 1, 'denominator' => 1];
        }

        $rates = Config::get('fx.rates', []);

        if (! isset($rates["$base.$quote"])) {
            throw new RuntimeException(
                "No FX rate configured for {$base}->{$quote}. Add it to config/fx.php."
            );
        }

        // Config carries a decimal string so operators can edit it as "15.05"
        // without thinking in basis points. Parse it into an exact fraction here.
        $scaled = (int) round(((float) $rates["$base.$quote"]) * 10000);

        return ['numerator' => $scaled, 'denominator' => 10000];
    }

    /**
     * Convert between currencies at the current rate. For display and quoting
     * only: anything a buyer is committed to must go through lock().
     */
    public function convert(int $minor, string $from = 'usd', string $to = 'ghs'): int
    {
        $rate = $this->currentRate($from, $to);

        // Round, for the same reason convertWithQuote() does: truncating loses a
        // minor unit on many conversions and the quoted figure must equal the
        // charged figure exactly.
        return (int) round(($minor * $rate['numerator']) / $rate['denominator']);
    }

    /**
     * Freeze the current rate into a persisted quote.
     *
     * Returns the existing quote when the lot already has one, so a retried
     * deposit cannot silently move the buyer's rate between two attempts.
     */
    public function lock(string $base = 'usd', string $quote = 'ghs', ?int $expiresInSeconds = 3600): FxQuote
    {
        $base = strtolower($base);
        $quote = strtolower($quote);

        return Cache::remember(
            "fx:lock:{$base}:{$quote}",
            60,
            function () use ($base, $quote, $expiresInSeconds) {
                $rate = $this->currentRate($base, $quote);

                return FxQuote::create([
                    'base_currency' => $base,
                    'quote_currency' => $quote,
                    'base_minor' => self::minorUnit($base),
                    'quote_minor' => self::minorUnit($quote),
                    'rate_numerator' => $rate['numerator'],
                    'rate_denominator' => $rate['denominator'],
                    'locked_at' => now(),
                    'expires_at' => now()->addSeconds($expiresInSeconds),
                ]);
            }
        );
    }

    /** Convert using a locked quote rather than the live rate. */
    public function convertWithQuote(int $minor, FxQuote $quote, ?string $into = null): int
    {
        $into = strtolower($into ?? $quote->quote_currency);

        if ($into === $quote->base_currency) {
            return $minor;
        }

        if ($into !== $quote->quote_currency) {
            throw new RuntimeException(
                "Quote is {$quote->base_currency}->{$quote->quote_currency}; cannot convert into {$into}."
            );
        }

        // Round rather than truncate: rounding down systematically loses a small
        // amount on every conversion, and over thousands of lots that becomes a
        // visible gap between the ledger and the bank.
        return (int) round(($minor * $quote->rate_numerator) / $quote->rate_denominator);
    }

    /** Display form of a rate: 150000/10000 becomes "15.0000". */
    public function formatRate(FxQuote $quote): string
    {
        return number_format($quote->rate_numerator / $quote->rate_denominator, 4);
    }

    /**
     * Convert a USD amount into every currency a buyer can pay in.
     *
     * Used by the storefront to show GHS and USD side by side from one source,
     * so the two numbers on the page can never disagree.
     *
     * @return array<string, int>
     */
    public function convertForDisplay(int $minorUsd): array
    {
        $out = ['usd' => $minorUsd];

        foreach ((array) Config::get('fx.display', ['ghs']) as $currency) {
            $out[$currency] = $this->convert($minorUsd, 'usd', $currency);
        }

        return $out;
    }

    private static function minorUnit(string $currency): int
    {
        $currency = strtolower($currency);

        if (! isset(self::MINOR[$currency])) {
            throw new RuntimeException("Unsupported currency: {$currency}");
        }

        return self::MINOR[$currency];
    }
}