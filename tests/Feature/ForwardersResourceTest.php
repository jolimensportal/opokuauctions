<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Logistics\Resources\Forwarders\Pages\CreateForwarder;
use App\Models\Forwarder;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A forwarder is a record, not a user: they never log in, the console books
 * on their behalf. Without one, a container cannot be booked or costed.
 */
class ForwardersResourceTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::factory()->create(['role' => Role::Ops]);
    }

    private function inLogisticsPanel(): void
    {
        Filament::setCurrentPanel('logistics');
    }

    public function test_an_operator_can_open_the_forwarders_list(): void
    {
        $this->actingAs($this->operator(), 'admin')
            ->get('/admin/logistics/forwarders')
            ->assertOk();
    }

    public function test_a_buyer_cannot_open_the_forwarders_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Buyer]), 'admin')
            ->get('/admin/logistics/forwarders')
            ->assertForbidden();
    }

    public function test_an_operator_can_create_a_forwarder(): void
    {
        $this->inLogisticsPanel();

        Livewire::actingAs($this->operator(), 'admin')
            ->test(CreateForwarder::class)
            ->fillForm([
                'name' => 'Atlantic Freight',
                'contact_name' => 'Kofi Mensah',
                'contact_phone' => '+233 20 000 0000',
                'contact_email' => 'kofi@atlantic.example',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('forwarders', [
            'name' => 'Atlantic Freight',
            'contact_email' => 'kofi@atlantic.example',
        ]);
    }

    public function test_a_forwarder_needs_a_name(): void
    {
        $this->inLogisticsPanel();

        Livewire::actingAs($this->operator(), 'admin')
            ->test(CreateForwarder::class)
            ->fillForm(['name' => ''])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);
    }

    public function test_a_forwarder_is_listed_with_its_container_count(): void
    {
        Forwarder::create(['id' => 'fwd_one', 'name' => 'Atlantic Freight']);

        $this->actingAs($this->operator(), 'admin')
            ->get('/admin/logistics/forwarders')
            ->assertOk()
            ->assertSee('Atlantic Freight');
    }
}
