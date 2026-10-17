<?php

namespace App\Services;

use App\Models\CurbWeight;
use App\Models\Lot;

/**
 * Working out how much a car weighs without weighing it.
 *
 * Freight is priced per kilo, so a car we cannot weigh cannot be quoted, and a
 * car that cannot be quoted is a car nobody bids on. This turns a make and
 * model into a curb weight so operations are not typing the same five numbers
 * for every Land Cruiser that crosses the Atlantic.
 *
 * Two limits are deliberate:
 *
 *  - **A suggestion, never an overwrite.** Operations know things a table does
 *    not: modified springs, a variant trim, a crane in the bed. A number a
 *    person typed outranks a number we inferred, always.
 *
 *  - **Exact vehicle, no guessing at families.** A Land Cruiser and a Land
 *    Cruiser Prado differ by around 200kg. A partial match that returned the
 *    base model's weight would look like an answer and be a different car, which
 *    is worse than returning nothing at all.
 *
 * Matching is trimmed and case-insensitive on both sides. "honda" and "Honda"
 * are the same car, and failing on a capital letter would leave half the fleet
 * unquotable in a way nobody notices until a lot page renders blank.
 */
final class CurbWeightLookup
{
    /** The curb weight for an exact make and model, or null if we do not know it. */
    public function forVehicle(string $make, string $model): ?float
    {
        $make = $this->normalise($make);
        $model = $this->normalise($model);

        if ($make === '' || $model === '') {
            return null;
        }

        $row = CurbWeight::query()
            ->whereRaw('lower(btrim(make)) = ?', [$make])
            ->whereRaw('lower(btrim(model)) = ?', [$model])
            ->first();

        return $row ? (float) $row->weight_kg : null;
    }

    /**
     * Fill in a lot's weight from the table, if we can and if nobody else has.
     *
     * Returns the lot either way, so a caller can use it whether or not
     * anything was written. An unknown vehicle leaves the lot alone rather than
     * storing a guess, because a stored guess is indistinguishable from a
     * measured weight to everything downstream.
     */
    public function applyTo(Lot $lot): Lot
    {
        // A weight already on the lot is either measured or was typed by
        // someone who knows the car. Either way it outranks this table.
        if ($lot->weight_kg !== null) {
            return $lot;
        }

        // Only a vehicle has a make and model to look up. A pallet is weighed
        // at intake, and a stored guess would be indistinguishable from a
        // measured weight to everything downstream.
        if (! $lot->isVehicle()) {
            return $lot;
        }

        $detail = $lot->vehicleDetail;

        if (! $detail) {
            return $lot;
        }

        $weight = $this->forVehicle((string) $detail->make, (string) $detail->model);

        if ($weight === null) {
            return $lot;
        }

        $lot->weight_kg = $weight;
        $lot->save();

        return $lot;
    }

    /**
     * Weigh every lot that has no weight yet.
     *
     * The `creating` hook only covers lots made from here on. A database that
     * already has inventory predates the weight column, and those rows will
     * never be created again, so nothing but an explicit pass fills them.
     *
     * Returns the counts rather than just doing it, because "how many lots are
     * still unquotable" is a question operations will otherwise answer by hand.
     *
     * @return array{filled: int, unweighable: int}
     */
    public function backfill(): array
    {
        $filled = 0;
        $unweighable = 0;

        // Vehicles only: the lookup table is keyed on make and model, and a
        // pallet is weighed at intake rather than looked up.
        Lot::where('kind', 'vehicle')
            ->where(function ($query) {
                $query->whereNull('weight_kg')->orWhere('weight_kg', '<=', 0);
            })
            ->with('vehicleDetail')
            ->chunkById(200, function ($lots) use (&$filled, &$unweighable) {
                foreach ($lots as $lot) {
                    $detail = $lot->vehicleDetail;

                    $weight = $detail
                        ? $this->forVehicle((string) $detail->make, (string) $detail->model)
                        : null;

                    if ($weight === null) {
                        $unweighable++;

                        continue;
                    }

                    $lot->weight_kg = $weight;
                    $lot->save();
                    $filled++;
                }
            });

        return ['filled' => $filled, 'unweighable' => $unweighable];
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}