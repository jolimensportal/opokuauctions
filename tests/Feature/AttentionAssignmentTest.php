<?php

namespace Tests\Feature;

use App\Enums\AttentionKind;
use App\Enums\Role;
use App\Models\AttentionAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Why this table exists at all: an exception is a query, not a row, so it has
 * nowhere to store who owns it. This table is the one piece of state the
 * console keeps, and it holds exactly one fact.
 */
class AttentionAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_who_owns_an_exception(): void
    {
        $owner = User::factory()->create(['role' => Role::Ops]);

        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-0007', $owner->id);

        $this->assertDatabaseHas('attention_assignments', [
            'kind' => AttentionKind::FailedMessage->value,
            'ref' => 'MSG-0007',
            'owner_id' => $owner->id,
        ]);
    }

    public function test_the_same_exception_is_never_assigned_twice(): void
    {
        $first = User::factory()->create(['role' => Role::Ops]);
        $second = User::factory()->create(['role' => Role::Ops]);

        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-0007', $first->id);
        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-0007', $second->id);

        // First detection wins, permanently. If the newest call overwrote the
        // stored owner, two staff could each believe they held the same task.
        $this->assertSame(1, AttentionAssignment::where('ref', 'MSG-0007')->count());
        $this->assertSame($first->id, AttentionAssignment::where('ref', 'MSG-0007')->first()->owner_id);
    }

    public function test_re_recording_returns_the_original_owner(): void
    {
        $owner = User::factory()->create(['role' => Role::Ops]);
        $other = User::factory()->create(['role' => Role::Ops]);

        $returned = AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-0007', $owner->id);
        $again = AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-0007', $other->id);

        $this->assertSame($owner->id, $returned);
        $this->assertSame($owner->id, $again);
    }

    public function test_different_kinds_of_the_same_reference_are_distinct(): void
    {
        $owner = User::factory()->create(['role' => Role::Ops]);

        AttentionAssignment::record(AttentionKind::FailedMessage, 'SHARED-1', $owner->id);
        AttentionAssignment::record(AttentionKind::LotUnweighed, 'SHARED-1', $owner->id);

        // The composite key must include kind, not just ref.
        $this->assertSame(2, AttentionAssignment::count());
    }

    public function test_owner_id_can_be_looked_up_by_kind_and_ref(): void
    {
        $owner = User::factory()->create(['role' => Role::Ops]);
        AttentionAssignment::record(AttentionKind::UnevidencedMoney, 'CLT-1', $owner->id);

        $this->assertSame(
            $owner->id,
            AttentionAssignment::ownerFor(AttentionKind::UnevidencedMoney, 'CLT-1'),
        );
    }

    public function test_an_unassigned_exception_returns_null(): void
    {
        $this->assertNull(AttentionAssignment::ownerFor(AttentionKind::WalletHeld, 'WAL-9'));
    }

    public function test_an_exception_can_be_released_back_to_the_pool(): void
    {
        $owner = User::factory()->create(['role' => Role::Ops]);
        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-0007', $owner->id);

        AttentionAssignment::release(AttentionKind::FailedMessage, 'MSG-0007');

        $this->assertNull(AttentionAssignment::ownerFor(AttentionKind::FailedMessage, 'MSG-0007'));
    }

    public function test_recording_a_null_owner_stores_nothing(): void
    {
        // Auto-assign must never invent a recipient. With no staff available
        // the exception stays unowned rather than being pinned to a Buyer.
        AttentionAssignment::record(AttentionKind::WalletHeld, 'WAL-1', null);

        $this->assertSame(0, AttentionAssignment::count());
        $this->assertNull(AttentionAssignment::ownerFor(AttentionKind::WalletHeld, 'WAL-1'));
    }

    public function test_it_knows_the_open_load_of_each_owner(): void
    {
        $kofi = User::factory()->create(['role' => Role::Ops]);
        $adwoa = User::factory()->create(['role' => Role::Ops]);

        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-1', $kofi->id);
        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-2', $kofi->id);
        AttentionAssignment::record(AttentionKind::LotUnweighed, 'LOT-1', $adwoa->id);

        $loads = AttentionAssignment::openLoad();

        $this->assertSame(2, $loads[$kofi->id]);
        $this->assertSame(1, $loads[$adwoa->id]);
    }
}