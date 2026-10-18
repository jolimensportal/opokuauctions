<?php

namespace App\Services;

use App\Models\FreightRate;
use App\Models\Lot;
use App\Models\ShipmentLot;

/**
 * What it costs to move one car along the corridor.
 *
 * The last number a buyer is shown and the first thing they ask about, so two
 * things matter more than the arithmetic:
 *
 *  1. **Never guess.** A car we cannot weigh, or a corridor we have not priced,
 *     gets no quote. A default weight would produce a plausible-looking figure
 *     that looks authoritative and is invented, and a buyer bids on it.
 *
 *  2. **Never store a quote.** Every figure is recomputed from the lot's weight
 *     and the live rate row. A persisted quote is a promise to honour a price
 *     operations may have corrected since, and it is also the first step
 *     towards charging a buyer a number they never agreed to.
 *
 * The freight line is per-kilo with a floor, insurance is flat per car, and the
 * pickup surcharge exists only for a buyer who asks us to collect the vehicle.
 * The floor applies to the freight line alone: it is the forwarder's minimum
 * consignment charge, and it says nothing about what insuring or fetching a
 * small car costs.
 */
final class QuoteService
{
    /**
     * The lane quoted today. Newark to Tema is the only corridor in service, so
     * it is named here rather than threaded through a parameter nobody varies.
     * When a second lane opens this becomes the thing that has to change.
     */
    public const LANE = 'na-tema';

    /**
     * Fixed-point scales, so the money path never touches a float.
     *
     * A weight is held in grams and a rate in hundredths of a cent per kilo, so
     * `weight_kg * usd_per_kg * 100` becomes an integer multiply and a single
     * divide. Doing this with floats would give 1800 * 0.32 = 576.0000000001,
     * which is a cent off on a quote a buyer is shown.
     */
    private const GRAMS_PER_KILO = 1000;
    private const RATE_SCALE = 10000;
    // grams x rate / FREIGHT_DIVISOR is the answer in cents: 1,800,000 x 3200
    // / 100,000 = 57,600, which is USD 576.00.
    private const FREIGHT_DIVISOR = self::GRAMS_PER_KILO * self::RATE_SCALE / 100;

    /**
     * The quote for a lot, or null when there is nothing honest to quote.
     *
     * Freight and insurance are **per-shipment** costs divided among the lots
     * aboard. They are not per-lot constants: the forwarder charges for the
     * container, so a lot's share has to be its share of the container, not a
     * fresh $250 floor and a flat $107 of insurance. On a car that comes out
     * the same. On a single garage item it comes out sane.
     *
     * A lot that is not aboard a container yet gets no quote. There is no
     * container cost to take a share of, and inventing one — the old flat
     * $250 — is exactly the number that made a headlamp look expensive.
     *
     * @param  bool  $pickup  whether we are collecting for the buyer
     */
    public function forLot(Lot $lot, bool $pickup = false): ?Quote
    {
        $weight = $lot->weight_kg === null ? null : (float) $lot->weight_kg;

        // A zero weight is a weight nobody has measured yet, not a vehicle that
        // weighs nothing. Both states mean the same thing: we cannot price it.
        if ($weight === null || $weight <= 0.0) {
            return null;
        }

        $rate = FreightRate::where('lane', self::LANE)->where('active', true)->first();

        if (! $rate) {
            return null;
        }

        $allocation = $this->shipmentAllocation($lot, $rate);

        if ($allocation === null) {
            return null;
        }

        [$freight, $insurance] = $allocation;

        // Collect-it-yourself is the default and costs nothing. The surcharge is
        // for the convenience we were asked for, never a default the buyer has
        // to opt out of.
        $pickupCents = $pickup ? (int) $rate->pickup_cents : 0;

        return new Quote(
            weightKg: $weight,
            freightCents: $freight,
            insuranceCents: $insurance,
            pickupCents: $pickupCents,
            totalCents: $freight + $insurance + $pickupCents,
            currencyCode: 'usd',
            available: true,
        );
    }

    /**
     * This lot's share of its container's freight and insurance.
     *
     * The container's own freight is the total weight aboard at the lane rate,
     * floored once at the forwarder's minimum consignment charge, and that one
     * figure is divided by weight across everything in the container. The floor
     * is therefore paid once per container rather than once per lot, which is
     * the entire point of the change.
     *
     * @return array{0: int, 1: int}|null  [freight share, insurance share]
     */
    private function shipmentAllocation(Lot $lot, FreightRate $rate): ?array
    {
        $link = ShipmentLot::where('lot_id', $lot->id)->first();

        if (! $link) {
            return null;
        }

        $siblings = ShipmentLot::where('shipment_id', $link->shipment_id)->with('lot')->get();

        $weights = [];
        $selfIndex = null;

        foreach ($siblings as $sibling) {
            $weights[] = (float) ($sibling->lot?->weight_kg ?? 0.0);

            if ($sibling->lot_id === $lot->id) {
                $selfIndex = array_key_last($weights);
            }
        }

        if ($selfIndex === null || $weights === []) {
            return null;
        }

        $freight = max(
            $this->freightCents(array_sum($weights), (float) $rate->usd_per_kg),
            (int) $rate->min_charge_cents,
        );

        // Largest-remainder, so the shares reconcile to the container total.
        $freightShares = Allocation::split($freight, $weights);
        $insuranceShares = Allocation::split((int) $rate->insurance_cents, $weights);

        return [$freightShares[$selfIndex], $insuranceShares[$selfIndex]];
    }

    /**
     * Weight in kilos times the lane rate, in whole cents.
     *
     * Rounded once, half up, at the end. Rounding the weight and the rate
     * separately would compound two roundings into one.
     */
    private function freightCents(float $weightKg, float $usdPerKg): int
    {
        $grams = (int) round($weightKg * self::GRAMS_PER_KILO);
        $rate = (int) round($usdPerKg * self::RATE_SCALE);

        // + half the divisor, then truncate, is round-half-up on integers.
        return intdiv($grams * $rate + intdiv(self::FREIGHT_DIVISOR, 2), self::FREIGHT_DIVISOR);
    }
}