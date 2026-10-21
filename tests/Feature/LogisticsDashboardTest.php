<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Forwarder;
use App\Models\Shipment;
use App\Models\ShipmentMilestone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The console's front door.
 *
 * It is the first screen an operator sees, so it has to answer the two
 * questions they actually have: what is moving, and what is stuck.
 */
class LogisticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_console_shows_the_pipeline_counters(): void
    {
        Forwarder::create(['id' => 'fwd_ok', 'name' => 'Atlantic Freight']);

        Shipment::create([
            'id' => 'shp_one',
            'container_no' => 'MSCU 0000 010',
            'status' => 'SAILING',
            'forwarder_id' => 'fwd_ok',
        ]);

        ShipmentMilestone::create([
            'id' => 'ms_one',
            'shipment_id' => 'shp_one',
            'type' => 'SAILING',
            'occurred_at' => now(),
            'source' => 'admin',
        ]);

        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin')
            ->get('/admin/logistics')
            ->assertOk()
            ->assertSee('In transit')
            ->assertSee('MSCU 0000 010');
    }

    public function test_the_console_names_what_is_stuck(): void
    {
        Forwarder::create(['id' => 'fwd_ok', 'name' => 'Atlantic Freight']);

        Shipment::create([
            'id' => 'shp_silent',
            'container_no' => 'MSCU 0000 011',
            'status' => 'SAILING',
            'forwarder_id' => 'fwd_ok',
        ]);

        ShipmentMilestone::create([
            'id' => 'ms_silent',
            'shipment_id' => 'shp_silent',
            'type' => 'SAILING',
            'occurred_at' => now()->subDays(5),
            'source' => 'admin',
        ]);

        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin')
            ->get('/admin/logistics')
            ->assertOk()
            ->assertSee('has not moved')
            ->assertSee('MSCU 0000 011');
    }

    public function test_the_console_says_when_nothing_is_stuck(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin')
            ->get('/admin/logistics')
            ->assertOk()
            ->assertSee('Nothing is stopped');
    }

    public function test_a_buyer_never_sees_the_console(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Buyer]), 'admin')
            ->get('/admin/logistics')
            ->assertForbidden();
    }
}
