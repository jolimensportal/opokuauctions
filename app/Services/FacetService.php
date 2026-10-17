<?php

namespace App\Services;

use App\Models\Lot;
use Illuminate\Support\Facades\DB;

/**
 * How many lots sit behind each filter option.
 *
 * This is the difference between a filter that looks like a filter and one that
 * looks like a shop. An option showing "(48)" tells a buyer their choice is
 * worth making before they click it; an option showing nothing is a guess.
 *
 * Every number here is a real count against the lot table. Nothing is
 * estimated, rounded up for optimism, or seeded to look healthy: an empty
 * filter is shown as empty, because a buyer who clicks "Pallets" and finds
 * nothing should be told that, not given a decorative tile.
 */
final class FacetService
{
    /**
     * Count open lots grouped by make, richest first.
     *
     * @return \Illuminate\Support\Collection<int, object{value:string, count:int}>
     */
    public function byMake(int $limit = 12)
    {
        return $this->countBy('make', $limit);
    }

    /** @return \Illuminate\Support\Collection<int, object{value:string, count:int}> */
    public function byCondition()
    {
        return $this->countBy('condition');
    }

    /** @return \Illuminate\Support\Collection<int, object{value:string, count:int}> */
    public function byCity(int $limit = 10)
    {
        return $this->countBy('city', $limit);
    }

    /**
     * Price bands, counted.
     *
     * Bands are defined in USD because that is the settlement currency, then
     * displayed through the FX service so a Ghanaian buyer sees cedi bands that
     * correspond. Fixed boundaries rather than dynamic ones: a filter whose
     * ranges move every time stock changes cannot be linked to or remembered.
     *
     * @var array<int, array{label:string, min:int, max:?int}>
     */
    public function byPriceBand(): array
    {
        $bands = [
            ['label' => 'Under USD 1,000', 'min' => 0, 'max' => 100_000],
            ['label' => 'USD 1,000 - 2,500', 'min' => 100_000, 'max' => 250_000],
            ['label' => 'USD 2,500 - 5,000', 'min' => 250_000, 'max' => 500_000],
            ['label' => 'USD 5,000 - 10,000', 'min' => 500_000, 'max' => 1_000_000],
            ['label' => 'Above USD 10,000', 'min' => 1_000_000, 'max' => null],
        ];

        $open = $this->openScope();

        return array_map(function (array $band) use ($open) {
            $query = $open->clone()->where('current_bid', '>=', $band['min']);

            if ($band['max'] !== null) {
                $query->where('current_bid', '<', $band['max']);
            }

            return ['label' => $band['label'], 'count' => (clone $query)->count()];
        }, $bands);
    }

    /**
     * Year bands. Newer stock is what most buyers want first, so this is
     * ordered newest to oldest rather than by size.
     *
     * @return \Illuminate\Support\Collection<int, object{value:string, count:int}>
     */
    public function byYear()
    {
        $ranges = [
            ['2024 & newer', 2024, null],
            ['2018 - 2023', 2018, 2023],
            ['2012 - 2017', 2012, 2017],
            ['2005 - 2011', 2005, 2011],
            ['Before 2005', null, 2004],
        ];

        $open = $this->openScope();

        return collect($ranges)->map(function (array $range) use ($open) {
            $query = $open->clone();

            if ($range[1] !== null) {
                $query->where('year', '>=', $range[1]);
            }

            if ($range[2] !== null) {
                $query->where('year', '<=', $range[2]);
            }

            return (object) [
                'value' => $range[0],
                'count' => (clone $query)->count(),
            ];
        });
    }

    /**
     * Mileage bands, in miles.
     *
     * @return \Illuminate\Support\Collection<int, object{value:string, count:int}>
     */
    public function byMileage()
    {
        $ranges = [
            ['Under 50,000 mi', null, 50_000],
            ['50,000 - 100,000 mi', 50_000, 100_000],
            ['100,000 - 150,000 mi', 100_000, 150_000],
            ['Above 150,000 mi', 150_000, null],
        ];

        $open = $this->openScope();

        return collect($ranges)->map(function (array $range) use ($open) {
            $query = $open->clone()->whereNotNull('odometer');

            if ($range[1] !== null) {
                $query->where('odometer', '>=', $range[1]);
            }

            if ($range[2] !== null) {
                $query->where('odometer', '<', $range[2]);
            }

            return (object) [
                'value' => $range[0],
                'count' => (clone $query)->count(),
            ];
        });
    }

    /**
     * Days remaining, as countdown bands. This is the axis an auction buyer
     * actually sorts on.
     *
     * @return \Illuminate\Support\Collection<int, object{value:string, count:int, max:int}>
     */
    public function byClosingSoon()
    {
        // Explicit lower AND upper hour bounds per band. The previous version
        // derived the upper bound from the band's own label index, so "Today"
        // and "Closing now" both queried the next hour and a lot closing in 40
        // minutes appeared in neither the right bucket nor the sum of them.
        $bands = [
            ['Closing now', 0, 1],
            ['Later today', 1, 24],
            ['This week', 24, 168],
            ['Later', 168, null],
        ];

        $open = $this->openScope()->whereNotNull('ends_at');

        return collect($bands)->map(function (array $band) use ($open) {
            $query = $open->clone()
                ->where('ends_at', '>', now()->addHours($band[1]))
                ->where('ends_at', '<=', now()->addHours($band[2] ?? 8760));

            return (object) [
                'value' => $band[0],
                'count' => (clone $query)->count(),
                'max' => $band[2],
            ];
        });
    }

    /** Total open lots: the inventory-scale number the hero leads with. */
    public function totalOpen(): int
    {
        return $this->openScope()->count();
    }

    /** Distinct yards we are buying from. */
    public function yardCount(): int
    {
        return (clone $this->openScope())->distinct()->count('city');
    }

    /** Distinct makes available. */
    public function makeCount(): int
    {
        return (clone $this->openScope())->distinct()->count('make');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Lot>
     */
    private function openScope()
    {
        return Lot::query()
            ->whereIn('status', ['LIVE', 'UPCOMING'])
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }

    /** @return \Illuminate\Support\Collection<int, object{value:string, count:int}> */
    private function countBy(string $column, ?int $limit = null)
    {
        $query = $this->openScope()
            ->select($column, DB::raw('COUNT(*) as aggregate'))
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->groupBy($column)
            ->orderByDesc('aggregate')
            ->orderBy($column);

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map(fn ($row) => (object) [
            'value' => $row->{$column},
            'count' => (int) $row->aggregate,
        ]);
    }
}