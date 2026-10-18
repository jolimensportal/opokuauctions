<?php

namespace App\Services;

use App\Data\ResolvedRate;
use App\Enums\IntakeMethod;
use App\Enums\QuoteUnavailableReason;
use App\Enums\ShipmentUnit;
use App\Models\PostcodeBand;
use App\Models\RateCardRow;
use Carbon\CarbonImmutable;

/**
 * Turning what a sender told us into exactly one price.
 *
 * Two steps, and neither of them ever guesses. First the postcode becomes a
 * zone; then the zone becomes a row. A gap at either step is a refusal with a
 * reason attached, not a fallback to a neighbouring rate — a fallback is how a
 * company quietly loses money on a lane it never sold.
 */
class FreightRateResolver
{
    public function resolve(
        string $postcode,
        string $country,
        string $destination,
        string $mode,
        ShipmentUnit $unit,
        IntakeMethod $intakeMethod,
        ?string $serviceLevel,
        CarbonImmutable $on,
    ): ResolvedRate {
        $normalised = Postcode::normalise($postcode, $country);

        if ($normalised === null) {
            return ResolvedRate::refused(QuoteUnavailableReason::UnreadablePostcode);
        }

        $band = PostcodeBand::resolve($normalised, $country);

        if ($band === null) {
            return ResolvedRate::refused(
                QuoteUnavailableReason::UnmappedPostcode,
                ['postcode' => $normalised],
            );
        }

        $candidates = $this->candidates(
            zoneId: $band->zone_id,
            destination: $destination,
            mode: $mode,
            unit: $unit,
            intakeMethod: $intakeMethod,
            serviceLevel: $serviceLevel,
        );

        if ($candidates->isEmpty()) {
            return ResolvedRate::refused(QuoteUnavailableReason::UnpricedLane);
        }

        // Sorted by id and filtered by window, so a closed row never shadows
        // the successor that replaced it.
        $effective = $candidates
            ->sortBy('id')
            ->first(fn (RateCardRow $row) => $row->isEffectiveOn($on));

        if ($effective === null) {
            // We did price this lane once; the price is stale. That is a
            // different problem from never having priced it, and the operator
            // needs to know which one they are looking at.
            return ResolvedRate::refused(QuoteUnavailableReason::ExpiredRate);
        }

        return ResolvedRate::found($effective);
    }

    /**
     * Rows that could price this request, most specific first.
     *
     * A named service level beats the catch-all. Falling back to 'standard' is
     * deliberate and bounded: standard is an explicitly priced row, not a
     * guess at what express ought to cost.
     *
     * @return \Illuminate\Support\Collection<int, RateCardRow>
     */
    private function candidates(
        string $zoneId,
        string $destination,
        string $mode,
        ShipmentUnit $unit,
        IntakeMethod $intakeMethod,
        ?string $serviceLevel,
    ) {
        $base = RateCardRow::query()
            ->where('origin_zone_id', $zoneId)
            ->where('destination', $destination)
            ->where('mode', $mode)
            ->where('unit', $unit->value)
            ->where('intake_method', $intakeMethod->value)
            ->with('rateCard')
            ->get();

        $exact = $serviceLevel === null
            ? collect()
            : $base->filter(fn (RateCardRow $row) => $row->service_level === $serviceLevel);

        if ($exact->isNotEmpty()) {
            return $exact;
        }

        return $base->filter(fn (RateCardRow $row) => $row->service_level === 'standard');
    }
}