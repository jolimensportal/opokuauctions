<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Logistics\Resources\Shipments\Pages\ListShipments;
use App\Models\Shipment;
use App\Models\User;
use App\Services\ShipmentMilestoneRecorder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Recording a milestone is the one action that moves freight.
 *
 * It writes the milestone and the container's state together, because a
 * milestone that does not move the container is a note, and a note is how a
 * container ends up silent for four days.
 */
class RecordMilestoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_a_milestone_writes_it_and_moves_the_container(): void
    {
        $shipment = Shipment::create([
            'id' => 'shp_test',
            'container_no' => 'MSCU 1234 567',
            'status' => 'BOOKING',
        ]);

        $operator = User::factory()->create(['role' => Role::Ops]);

        app(ShipmentMilestoneRecorder::class)->record(
            shipment: $shipment,
            type: 'DEPARTED',
            occurredAt: now(),
            notes: 'Left Newark',
            recordedBy: $operator,
        );

        $this->assertDatabaseHas('shipment_milestones', [
            'shipment_id' => 'shp_test',
            'type' => 'DEPARTED',
            'source' => 'admin',
            'recorded_by_user_id' => $operator->id,
        ]);

        $this->assertSame('DEPARTED', $shipment->fresh()->status);
    }

    public function test_a_milestone_from_a_feed_is_not_recorded_by_an_operator(): void
    {
        $shipment = Shipment::create([
            'id' => 'shp_feed',
            'container_no' => 'MSCU 2222 333',
        ]);

        app(ShipmentMilestoneRecorder::class)->record(
            shipment: $shipment,
            type: 'SAILING',
            occurredAt: now(),
            notes: null,
            recordedBy: null,
            source: 'feed',
        );

        $this->assertDatabaseHas('shipment_milestones', [
            'shipment_id' => 'shp_feed',
            'source' => 'feed',
            'recorded_by_user_id' => null,
        ]);
    }

    public function test_an_operator_can_record_a_milestone_from_the_container_list(): void
    {
        Filament::setCurrentPanel('logistics');

        $shipment = Shipment::create([
            'id' => 'shp_ui',
            'container_no' => 'MSCU 4444 555',
            'status' => 'BOOKING',
        ]);

        $operator = User::factory()->create(['role' => Role::Ops]);

        Livewire::actingAs($operator, 'admin')
            ->test(ListShipments::class)
            ->callTableAction('recordMilestone', $shipment, data: [
                'type' => 'LOADED',
                'occurred_at' => now()->toDateTimeString(),
                'notes' => 'Loaded at Newark',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('shipment_milestones', [
            'shipment_id' => 'shp_ui',
            'type' => 'LOADED',
            'notes' => 'Loaded at Newark',
            'recorded_by_user_id' => $operator->id,
        ]);

        $this->assertSame('LOADED', $shipment->fresh()->status);
    }

    public function test_the_container_keeps_its_state_when_nothing_is_recorded(): void
    {
        $shipment = Shipment::create([
            'id' => 'shp_untouched',
            'container_no' => 'MSCU 3333 444',
            'status' => 'SAILING',
        ]);

        $this->assertSame('SAILING', $shipment->fresh()->status);
        $this->assertDatabaseCount('shipment_milestones', 0);
    }
}
