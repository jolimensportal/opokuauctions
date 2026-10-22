<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The operations console replaces Filament's own dashboard.
 *
 * Filament's dashboard is a greeting, a form and a panel that says
 * "Filament v5.10.2" with links to Filament's documentation. None of that
 * tells an operator what is happening.
 */
class OperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_console_is_the_front_door_of_the_operations_panel(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Operations')
            ->assertSee('Required action');
    }

    public function test_it_does_not_advertise_filament(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('Filament v5');
    }

    public function test_a_buyer_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Buyer]), 'admin')
            ->get('/admin')
            ->assertForbidden();
    }
}
