<?php

namespace Tests\Feature;

use App\Enums\AttentionKind;
use App\Enums\Role;
use App\Models\AttentionAssignment;
use App\Models\User;
use App\Services\AttentionAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Assignment is decided once, by workload, and then never revisited.
 */
class AttentionAssignerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_assigns_to_a_staff_member(): void
    {
        $kofi = User::factory()->create(['role' => Role::Ops]);

        $owner = app(AttentionAssigner::class)->assign(AttentionKind::FailedMessage, 'MSG-1');

        $this->assertSame($kofi->id, $owner);
    }

    public function test_the_least_loaded_staff_member_is_chosen(): void
    {
        $busy = User::factory()->create(['role' => Role::Ops, 'name' => 'Busy']);
        $free = User::factory()->create(['role' => Role::Ops, 'name' => 'Free']);

        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-1', $busy->id);
        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-2', $busy->id);
        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-3', $busy->id);

        $owner = app(AttentionAssigner::class)->assign(AttentionKind::LotUnweighed, 'LOT-9');

        $this->assertSame($free->id, $owner);
    }

    public function test_assignment_is_stable_across_repeated_calls(): void
    {
        User::factory()->create(['role' => Role::Ops]);

        $assigner = app(AttentionAssigner::class);
        $first = $assigner->assign(AttentionKind::FailedMessage, 'MSG-1');

        // A later, more loaded operator must not steal an already-owned item.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, $assigner->assign(AttentionKind::FailedMessage, 'MSG-1'));
        }
    }

    public function test_an_already_assigned_exception_is_never_reassigned(): void
    {
        $kofi = User::factory()->create(['role' => Role::Ops, 'name' => 'Kofi']);
        $adwoa = User::factory()->create(['role' => Role::Ops, 'name' => 'Adwoa']);

        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-1', $kofi->id);

        $owner = app(AttentionAssigner::class)->assign(AttentionKind::FailedMessage, 'MSG-1');

        $this->assertSame($kofi->id, $owner);
        $this->assertNotSame($adwoa->id, $owner);
    }

    public function test_buyers_are_never_assigned(): void
    {
        User::factory()->create(['role' => Role::Buyer]);
        User::factory()->create(['role' => Role::Buyer]);
        $ops = User::factory()->create(['role' => Role::Ops]);

        // Buyers outnumber staff two to one. Assigning to either would put
        // operational work on someone with no console access.
        $this->assertSame(
            $ops->id,
            app(AttentionAssigner::class)->assign(AttentionKind::WalletHeld, 'WAL-1'),
        );
    }

    public function test_no_staff_yields_null_rather_than_a_buyer(): void
    {
        User::factory()->create(['role' => Role::Buyer]);

        $this->assertNull(app(AttentionAssigner::class)->assign(AttentionKind::WalletHeld, 'WAL-1'));
    }

    public function test_work_is_spread_rather_than_piled_on_one_person(): void
    {
        User::factory()->count(3)->create(['role' => Role::Ops]);

        $assigner = app(AttentionAssigner::class);

        for ($i = 0; $i < 6; $i++) {
            $assigner->assign(AttentionKind::FailedMessage, "MSG-{$i}");
        }

        // Six items across three staff: nobody may hold more than two.
        foreach (AttentionAssignment::openLoad() as $owner => $load) {
            $this->assertLessThanOrEqual(2, $load, 'Assignment must not pile onto one person.');
        }
    }

    public function test_stranded_items_are_released_when_an_owner_loses_panel_access(): void
    {
        $staff = User::factory()->create(['role' => Role::Ops]);
        User::factory()->create(['role' => Role::Ops]);

        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-1', $staff->id);
        $this->assertSame(1, AttentionAssignment::count());

        $staff->update(['role' => Role::Buyer]);

        app(AttentionAssigner::class)->releaseStranded();

        // The item must not stay owned by someone who cannot open the panel.
        $this->assertNull(AttentionAssignment::ownerFor(AttentionKind::FailedMessage, 'MSG-1'));
    }

    public function test_releasing_stranded_items_keeps_items_owned_by_real_staff(): void
    {
        $real = User::factory()->create(['role' => Role::Ops]);
        $departed = User::factory()->create(['role' => Role::Ops]);

        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-1', $real->id);
        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-2', $departed->id);

        $departed->update(['role' => Role::Buyer]);

        app(AttentionAssigner::class)->releaseStranded();

        // A blanket wipe would be worse than the problem: it would unassign the
        // items belonging to staff who are still here.
        $this->assertSame($real->id, AttentionAssignment::ownerFor(AttentionKind::FailedMessage, 'MSG-1'));
        $this->assertNull(AttentionAssignment::ownerFor(AttentionKind::FailedMessage, 'MSG-2'));
    }

    public function test_release_stranded_reports_how_many_it_released(): void
    {
        $departed = User::factory()->create(['role' => Role::Ops]);
        AttentionAssignment::record(AttentionKind::FailedMessage, 'MSG-1', $departed->id);

        $departed->update(['role' => Role::Buyer]);

        $this->assertSame(1, app(AttentionAssigner::class)->releaseStranded());
    }
}