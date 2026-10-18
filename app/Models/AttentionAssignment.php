<?php

namespace App\Models;

use App\Enums\AttentionKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Who owns one open exception.
 *
 * Deliberately the dumbest possible model: no scopes, no relationships beyond
 * the owner, and no derived columns. Its only job is to make a fact true — the
 * owner chosen at first detection — and it never becomes the source of truth for
 * whether an exception exists.
 */
class AttentionAssignment extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = ['kind', 'ref'];

    public $timestamps = false;

    protected $fillable = ['kind', 'ref', 'owner_id'];

    protected function casts(): array
    {
        return [
            'kind' => AttentionKind::class,
            'assigned_at' => 'immutable_datetime',
        ];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Store the owner of an exception, or return the one already stored.
     *
     * insertOrIgnore is the whole design. If this exception already has an
     * owner, the insert is silently skipped and the original is returned, so
     * assignment is decided exactly once — at first detection — and never
     * recomputed. Two operators opening the queue a second apart therefore see
     * the same name, which is the entire point.
     *
     * @param  int|null  $ownerId  null when no staff member exists
     */
    public static function record(AttentionKind $kind, string $ref, ?int $ownerId): ?int
    {
        if ($ownerId === null) {
            // Never invent a recipient. An unassigned exception is a real state.
            return self::ownerFor($kind, $ref);
        }

        DB::table('attention_assignments')->insertOrIgnore([
            'kind' => $kind->value,
            'ref' => $ref,
            'owner_id' => $ownerId,
            'assigned_at' => now(),
        ]);

        return self::ownerFor($kind, $ref);
    }

    public static function ownerFor(AttentionKind $kind, string $ref): ?int
    {
        $row = DB::table('attention_assignments')
            ->where('kind', $kind->value)
            ->where('ref', $ref)
            ->value('owner_id');

        return $row === null ? null : (int) $row;
    }

    /**
     * Hand an exception back to the pool.
     *
     * Used when the stored owner is no longer staff — an account deleted, or a
     * role changed to Buyer — so the item does not sit permanently owned by
     * someone who can no longer act on it.
     */
    public static function release(AttentionKind $kind, string $ref): void
    {
        DB::table('attention_assignments')
            ->where('kind', $kind->value)
            ->where('ref', $ref)
            ->delete();
    }

    /**
     * How many open items each owner holds, keyed by user id.
     *
     * @return array<int, int>
     */
    public static function openLoad(): array
    {
        $rows = DB::table('attention_assignments')
            ->selectRaw('owner_id, count(*) as total')
            ->groupBy('owner_id')
            ->get();

        // Built by hand rather than with collapse(): Collection::collapse()
        // merges through array_merge, which renumbers integer keys and would
        // silently turn owner id 7 into owner id 0.
        $loads = [];

        foreach ($rows as $row) {
            $loads[(int) $row->owner_id] = (int) $row->total;
        }

        return $loads;
    }
}