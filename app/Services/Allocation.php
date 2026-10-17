<?php

namespace App\Services;

/**
 * Dividing one integer amount between several recipients.
 *
 * The forwarder charges a minimum consignment charge for the container, not for
 * each thing in it. A 13-cent headlamp in a container of forty other things does
 * not carry $250 of freight on its own, so the container cost is divided here
 * and the buyer's share falls out.
 *
 * Largest-remainder, not proportional rounding: floor every share, then give the
 * leftover cents to the recipients whose fractional part was largest. This is the
 * only approach where the shares always sum back to the total. Rounding each
 * share independently invents or loses a cent per lot, and a shipment whose
 * shares do not reconcile against the ledger is a shipment nobody can audit.
 */
final class Allocation
{
    /**
     * Split $totalCents across $weights, proportionally.
     *
     * A zero weight is valid and receives an equal share rather than nothing:
     * a lot nobody weighed still takes up room, and dumping its cost on the
     * lots that happen to have weights is how a pallet ends up paying for a
     * headlamp.
     *
     * @param  array<int, float>  $weights
     * @return array<int, int>  one share per weight, in the same order
     */
    public static function split(int $totalCents, array $weights): array
    {
        $count = count($weights);

        if ($count === 0) {
            return [];
        }

        // A weight of zero is "nobody weighed this", not "this weighs nothing",
        // so it is replaced with the mean of the weights that do exist. Pure
        // proportional division would hand it nothing and quietly move its cost
        // onto its neighbours.
        $weights = array_values(array_map(
            static fn ($weight) => (float) $weight > 0.0 ? (float) $weight : null,
            $weights,
        ));

        $known = array_values(array_filter($weights, static fn ($w) => $w !== null));

        if ($known === []) {
            return self::divideEvenly($totalCents, $count);
        }

        $fallback = array_sum($known) / count($known);
        $weights = array_map(static fn ($w) => $w ?? $fallback, $weights);

        $sum = array_sum($weights);

        if ($sum <= 0.0) {
            return self::divideEvenly($totalCents, $count);
        }

        $shares = [];
        $remainders = [];
        $assigned = 0;

        foreach ($weights as $index => $weight) {
            $exact = ($totalCents * $weight) / $sum;

            $shares[$index] = (int) floor($exact);
            $remainders[$index] = $exact - floor($exact);
            $assigned += $shares[$index];
        }

        $leftover = $totalCents - $assigned;

        // Largest remainder first, ties broken by position so the result is
        // deterministic rather than dependent on sort stability.
        $order = array_keys($remainders);

        usort($order, function (int $a, int $b) use ($remainders): int {
            return $remainders[$b] <=> $remainders[$a] ?: $a <=> $b;
        });

        for ($i = 0; $i < $leftover; $i++) {
            $shares[$order[$i % $count]]++;
        }

        ksort($shares);

        return array_values($shares);
    }

    /**
     * @return array<int, int>
     */
    private static function divideEvenly(int $totalCents, int $count): array
    {
        $base = intdiv($totalCents, $count);
        $leftover = $totalCents - ($base * $count);

        $shares = array_fill(0, $count, $base);

        for ($i = 0; $i < $leftover; $i++) {
            $shares[$i]++;
        }

        return $shares;
    }
}