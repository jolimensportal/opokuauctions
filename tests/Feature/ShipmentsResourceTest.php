<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Logistics\Resources\Shipments\Pages\CreateShipment;
use App\Models\Forwarder;
use App\Models\Shipment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A container has to be a record an operator can open, list and create.
 *
 * Until this existed, a shipment could only be created by a seeder. The
 * corridor board could show one, but nothing could add one, and the whole
 * freight workflow hangs off being able to.
 *
 * The form is driven through Livewire, the way Filament actually resolves it.
 * A plain POST to the create URL is a 405: the page is a Livewire component
 * and only GET means "show the form".
 */
class ShipmentsResourceTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::factory()->create(['role' => Role::Ops]);
    }

    /**
     * The page belongs to the logistics panel, and Filament resolves a
     * resource's URLs against the current panel. Without this the page looks
     * for a route in the operations panel and blows up on the breadcrumb.
     */
    private function inLogisticsPanel(): void
    {
        Filament::setCurrentPanel('logistics');
    }

    public function test_an_operator_can_open_the_shipments_list(): void
    {
        $this->actingAs($this->operator(), 'admin')
            ->get('/admin/shipments')
            ->assertOk();
    }

    public function test_a_buyer_cannot_open_the_shipments_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Buyer]), 'admin')
            ->get('/admin/shipments')
            ->assertForbidden();
    }

    public function test_an_operator_can_create_a_container(): void
    {
        $forwarder = Forwarder::create([
            'id' => 'fwd_test',
            'name' => 'Atlantic Freight',
        ]);

        $this->inLogisticsPanel();

        Livewire::actingAs($this->operator(), 'admin')
            ->test(CreateShipment::class)
            ->fillForm([
                'container_no' => 'MSCU 1111 222',
                'vessel' => 'MV Test Trader',
                'voyage' => '007W',
                'seaport_origin' => 'Newark',
                'seaport_destination' => 'Tema',
                'forwarder_id' => $forwarder->id,
                'status' => 'BOOKING',
                'freight_cost' => 0,
                'insurance_cost' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('shipments', [
            'container_no' => 'MSCU 1111 222',
            'seaport_destination' => 'Tema',
            'forwarder_id' => 'fwd_test',
        ]);
    }

    public function test_a_container_number_is_required(): void
    {
        $this->inLogisticsPanel();

        Livewire::actingAs($this->operator(), 'admin')
            ->test(CreateShipment::class)
            ->fillForm([
                'container_no' => '',
                'seaport_destination' => 'Tema',
            ])
            ->call('create')
            ->assertHasFormErrors(['container_no' => 'required']);
    }

    public function test_a_container_number_cannot_be_used_twice(): void
    {
        Shipment::create([
            'id' => 'shp_existing',
            'container_no' => 'MSCU 9999 000',
        ]);

        $this->inLogisticsPanel();

        Livewire::actingAs($this->operator(), 'admin')
            ->test(CreateShipment::class)
            ->fillForm([
                'container_no' => 'MSCU 9999 000',
                'seaport_destination' => 'Tema',
            ])
            ->call('create')
            ->assertHasFormErrors(['container_no' => 'unique']);
    }
}
