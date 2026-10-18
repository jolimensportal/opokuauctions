<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ClearanceTask;
use App\Models\Lot;
use App\Models\ShipmentLot;
use App\Models\User;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The console page. The access rule matters as much as the rendering: an
 * operator console that leaks to a buyer is a data breach, not a styling bug.
 */
class AttentionQueuePageTest extends TestCase
{
    use RefreshDatabase;

    private function shipment(): string
    {
        $shipment = app(ShipmentService::class)->createShipment([
            'container_no' => 'MSKU'.uniqid(),
            'seaport_destination' => 'Tema',
            'freight_cost' => 900_00,
            'insurance_cost' => 100_00,
        ]);

        // Clear the template so only the exception under test remains.
        ClearanceTask::where('shipment_id', $shipment->id)->update([
            'status' => 'complete',
            'receipt_path' => 'receipts/ok.pdf',
        ]);

        ShipmentLot::create([
            'shipment_id' => $shipment->id,
            'lot_id' => $this->lot('lot_q1', 1500)->id,
            'quantity' => 1,
        ]);

        return $shipment->id;
    }

    private function lot(string $id, ?float $weight): Lot
    {
        return Lot::create([
            'id' => $id,
            'kind' => 'vehicle',
            'provenance' => 'salvage_auction',
            'title' => 'Toyota Camry',
            'city' => 'Detroit',
            'photos' => [],
            'starting_bid' => 100000,
            'reserve_price' => 500000,
            'bid_increment' => 25000,
            'current_bid' => 100000,
            'currency_code' => 'usd',
            'status' => 'LIVE',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'weight_kg' => $weight,
        ]);
    }

    public function test_a_buyer_cannot_open_the_console(): void
    {
        $buyer = User::factory()->create(['role' => Role::Buyer]);

        $this->actingAs($buyer)->get('/admin/attention-queue')->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/admin/attention-queue')->assertRedirect();
    }

    public function test_an_ops_user_can_open_the_console(): void
    {
        $ops = User::factory()->create(['role' => Role::Ops]);

        $this->actingAs($ops)->get('/admin/attention-queue')->assertOk();
    }

    public function test_an_owner_can_open_the_console(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner]);

        $this->actingAs($owner)->get('/admin/attention-queue')->assertOk();
    }

    public function test_an_unweighed_lot_appears_in_the_queue(): void
    {
        $this->lot('lot_unweighed', null);

        $ops = User::factory()->create(['role' => Role::Ops]);

        $this->actingAs($ops)
            ->get('/admin/attention-queue')
            ->assertOk()
            ->assertSee('lot_unweighed');
    }

    public function test_the_queue_names_the_fault_in_plain_language(): void
    {
        $this->lot('lot_plain', null);

        $ops = User::factory()->create(['role' => Role::Ops]);

        $this->actingAs($ops)
            ->get('/admin/attention-queue')
            ->assertOk()
            ->assertSee('cannot be freighted');
    }

    public function test_an_unevidenced_money_task_appears_as_critical(): void
    {
        $shipmentId = $this->shipment();

        ClearanceTask::where('shipment_id', $shipmentId)
            ->where('type', 'DUTY_PAID')
            ->update(['status' => 'complete', 'cost' => 42000, 'receipt_path' => null]);

        $ops = User::factory()->create(['role' => Role::Ops]);

        $this->actingAs($ops)
            ->get('/admin/attention-queue')
            ->assertOk()
            ->assertSee('Critical');
    }

    public function test_a_clean_platform_shows_an_empty_state(): void
    {
        $this->shipment();

        $ops = User::factory()->create(['role' => Role::Ops]);

        // An empty queue must say so plainly rather than rendering a blank box.
        $this->actingAs($ops)
            ->get('/admin/attention-queue')
            ->assertOk()
            ->assertSee('Nothing needs attention');
    }

    public function test_the_page_renders_each_items_owner(): void
    {
        $this->lot('lot_owned', null);

        $kofi = User::factory()->create(['role' => Role::Ops, 'name' => 'Kofi Mensah']);

        $this->actingAs($kofi)
            ->get('/admin/attention-queue')
            ->assertOk()
            ->assertSee('Kofi Mensah');
    }

    public function test_the_page_renders_severity_tiles(): void
    {
        $this->lot('lot_tiles', null);

        $ops = User::factory()->create(['role' => Role::Ops]);

        $this->actingAs($ops)
            ->get('/admin/attention-queue')
            ->assertOk()
            ->assertSee('Critical')
            ->assertSee('High')
            ->assertSee('Medium')
            ->assertSee('Low');
    }

    public function test_a_weighed_lot_does_not_appear(): void
    {
        $this->lot('lot_weighed', 1520);

        $ops = User::factory()->create(['role' => Role::Ops]);

        $this->actingAs($ops)
            ->get('/admin/attention-queue')
            ->assertOk()
            ->assertDontSee('lot_weighed');
    }
}