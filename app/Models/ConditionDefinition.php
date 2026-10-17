<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The condition vocabulary, as data rather than a hardcoded array.
 *
 * This exists so the sentence under each condition can be edited without a
 * deploy: those blurbs are sales copy, they change with the season, and they
 * used to live in a PHP array inside a controller.
 */
class ConditionDefinition extends Model
{
    /** Singular on purpose, matching the existing lot / bid / wallet tables. */
    protected $table = 'condition_definition';

    /** No timestamps: the table has none, matching lot and bid. */
    public $timestamps = false;

    protected $fillable = ['kind', 'code', 'label', 'blurb', 'sort'];

    protected $casts = ['sort' => 'integer'];

    /**
     * The vocabulary for one kind, in the order it should be shown.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function forKind(string $kind)
    {
        return self::query()
            ->where('kind', $kind)
            ->orderBy('sort')
            ->orderBy('label')
            ->get();
    }
}