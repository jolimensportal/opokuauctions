<?php

namespace App\Services;

use App\Data\FreightQuote;
use App\Data\QuoteResult;
use App\Enums\IntakeMethod;
use App\Enums\QuoteUnavailableReason;
use App\Enums\ShipmentUnit;

/**
 * The price a sender is shown.
 *
 * Resolves a rate, prices billable weight against it, and adds a pickup line
 * when asked. It guesses nothing: an undimensioned parcel, an unmapped postcode
 * or an expired rate all return a reason instead of a number.
 */
class FreightQuoteService
{
    /**
     * Standard pallet footprint in whole centimetres: 120 x 120 x 150.
     *
     * Pallet pricing is by footprint rather than by the sender's dimensions,
     * so a customer cannot understate the box. A rate card per footprint class
     * would replace this constant and nothing else.
     */
    private const PALLET_LENGTH_CM = 120;

    private const PALLET_WIDTH_CM = 120;

    private const PALLET_HEIGHT_CM = 150;

    public function __construct(private FreightRateResolver $resolver) {}

    public function quote(
        string $postcode,
        string $country,
        string $destination,
        string $mode,
        ShipmentUnit $unit,
        IntakeMethod $intakeMethod,
        float $weightKg,
        ?int $lengthCm,
        ?int $widthCm,
        ?int $heightCm,
        bool $pickup = false,
        ?string $serviceLevel = 'standard',
    ): QuoteResult {
        $resolved = $this->resolver->resolve(
            postcode: $postcode,
            country: $country,
            destination: $destination,
            mode: $mode,
            unit: $unit,
            intakeMethod: $intakeMethod,
            serviceLevel: $serviceLevel,
            on: now()->toImmutable(),
        );

        if (! $resolved->isAvailable()) {
            return QuoteResult::refused($resolved->reason, $resolved->context);
        }

        $row = $resolved->row;

        [$l, $w, $h] = $unit->isPallet()
            ? [self::PALLET_LENGTH_CM, self::PALLET_WIDTH_CM, self::PALLET_HEIGHT_CM]
            : [$lengthCm, $widthCm, $heightCm];

        // A parcel needs all three dimensions because air freight is priced on
        // the space it takes. A pallet has ours.
        if ($l === null || $w === null || $h === null) {
            return QuoteResult::refused(QuoteUnavailableReason::MissingDimensions);
        }

        $billable = BillableWeight::grams($weightKg, $l, $w, $h, $row->divisor);

        if ($billable === null) {
            return QuoteResult::refused(QuoteUnavailableReason::MissingDimensions);
        }

        $freight = BillableWeight::floor(
            BillableWeight::cents($billable, $row->per_kg_cents),
            $row->min_charge_cents,
        );

        // A pallet's floor binds on a light pallet and does not override real
        // weight-based freight on a heavy one.
        if ($row->per_pallet_cents !== null) {
            $freight = max($freight, $row->per_pallet_cents);
        }

        $lines = [['label' => 'Freight to Tema', 'cents' => $freight]];

        if ($pickup) {
            $pickupCents = $this->pickupCharge(
                postcode: $postcode,
                country: $country,
                destination: $destination,
                mode: $mode,
                unit: $unit,
            );

            // Refused rather than silently free: a customer who books
            // expecting collection and gets none is worse than one who was
            // told the price is unavailable.
            if ($pickupCents === null) {
                return QuoteResult::refused(
                    QuoteUnavailableReason::UnpricedLane,
                    ['service' => 'pickup'],
                );
            }

            $lines[] = ['label' => 'Courier collection', 'cents' => $pickupCents];
        }

        return QuoteResult::quoted(
            FreightQuote::of($lines, $row->currency(), $row->id),
        );
    }

    /**
     * The courier stop, priced by its own rate row.
     *
     * A stop is not a kilo, so it is not a flat fee bolted onto the quote: it
     * is a rate row in its own right, whose high minimum prices a stop
     * correctly without a second pricing engine.
     */
    private function pickupCharge(
        string $postcode,
        string $country,
        string $destination,
        string $mode,
        ShipmentUnit $unit,
    ): ?int {
        $resolved = $this->resolver->resolve(
            postcode: $postcode,
            country: $country,
            destination: $destination,
            mode: $mode,
            unit: $unit,
            intakeMethod: IntakeMethod::PartnerPickup,
            serviceLevel: 'pickup',
            on: now()->toImmutable(),
        );

        return $resolved->row?->min_charge_cents;
    }
}