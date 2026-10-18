<?php

namespace App\Services;

use App\Enums\AttentionKind;
use App\Enums\Role;
use App\Models\AttentionAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deciding who owns an open exception.
 *
 * Two rules, and both exist because of the failure they prevent:
 *
 * 1. Assigned by least current load, so one operator does not inherit every
 *    problem while another sees an empty queue.
 * 2. Decided once. assign() is safe to call on every page load because
 *    AttentionAssignment::record() ignores a second insert, so the stored owner
 *    is authoritative and never recomputed.
 *
 * Reassignment is deliberately absent: it would require overwriting the stored
 * value, which is exactly the property that makes it trustworthy.
 */
class AttentionAssigner
{
    /**
     * The owner of this exception, choosing one only if it has none.
     */
    public function assign(AttentionKind $kind, string $ref): ?int
    {
        $existing = AttentionAssignment::ownerFor($kind, $ref);

        if ($existing !== null) {
            return $existing;
        }

        return AttentionAssignment::record($kind, $ref, $this->leastLoaded());
    }

    /**
     * The staff member holding the fewest open items.
     *
     * Ties break on id so the choice is deterministic. Without that, row order
     * decides and the same item can land on different people on consecutive
     * requests — which would make "assigned once" a lie.
     */
    public function leastLoaded(): ?int
    {
        $staff = User::query()
            ->where('role', '!=', Role::Buyer)
            ->orderBy('id')
            ->pluck('id');

        if ($staff->isEmpty()) {
            return null;
        }

        $load = AttentionAssignment::openLoad();

        $counts = $staff->mapWithKeys(fn (int $id) => [$id => $load[$id] ?? 0]);

        return (int) $counts->sort()->keys()->first();
    }

    /**
     * Hand back items owned by someone who can no longer act on them.
     *
     * Without this, a demoted or deleted account would leave exceptions
     * permanently assigned to a person with no console access, and they would
     * never appear in anyone's load.
     *
     * @return int  how many items were released
     */
    public function releaseStranded(): int
    {
        $staffIds = User::query()
            ->where('role', '!=', Role::Buyer)
            ->pluck('id')
            ->all();

        return DB::table('attention_assignments')
            ->whereNotIn('owner_id', $staffIds)
            ->delete();
    }
}