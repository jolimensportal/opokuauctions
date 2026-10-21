<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\ClearanceTasks\ClearanceTaskResource;
use App\Models\ClearanceTask;
use App\Models\User;
use App\Services\ClearanceService;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The clearance screen is where an operator records what was actually paid, so
 * it gets the same scrutiny as the money services behind it.
 */
class ClearanceConsoleTest extends TestCase
{
    use RefreshDatabase;

    private ClearanceService $clearance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearance = app(ClearanceService::class);
    }

    private function shipment(): string
    {
        return app(ShipmentService::class)->createShipment([
            'container_no' => 'MSKU'.uniqid(),
            'seaport_destination' => 'Tema',
        ])->id;
    }

    public function test_the_template_is_generated_when_a_shipment_is_created(): void
    {
        $shipmentId = $this->shipment();

        $this->assertSame(
            9,
            ClearanceTask::where('shipment_id', $shipmentId)->count(),
            'the checklist should exist before the container ever arrives'
        );
    }

    public function test_the_screen_lists_the_tasks_in_order(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin');
        $shipmentId = $this->shipment();

        $this->get(ClearanceTaskResource::getUrl())
            ->assertSuccessful()
            ->assertSee('Bill of lading received from forwarder')
            ->assertSee('Gate-out executed at Tema port');
    }

    public function test_a_completed_money_task_without_a_receipt_shows_the_warning(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin');
        $shipmentId = $this->shipment();
        $ops = User::where('role', Role::Ops)->first();

        $duty = ClearanceTask::where('shipment_id', $shipmentId)->where('type', 'DUTY_PAID')->first();
        $this->clearance->completeTask($duty->id, $ops, 250_00); // no receipt

        $this->get(ClearanceTaskResource::getUrl())
            ->assertSuccessful()
            ->assertSee('missing');

        $this->assertTrue($duty->refresh()->isUnevidenced());
    }

    public function test_tasks_cannot_be_created_or_deleted_from_the_console(): void
    {
        $this->assertFalse(ClearanceTaskResource::canCreate());
        $this->assertFalse(ClearanceTaskResource::canEdit(null));
        $this->assertFalse(ClearanceTaskResource::canDelete(null));
    }

    public function test_buyers_cannot_reach_the_clearance_console(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Buyer]), 'admin');

        $this->get(ClearanceTaskResource::getUrl())->assertForbidden();
    }
}
