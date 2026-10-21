<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The logistics console is a separate panel on the same guard.
 *
 * Separate, because it has a different job and a different navigation: freight
 * is grouped by lane, the auction is grouped by market and money. Same guard,
 * because an operator who has signed in to one has signed in to the other —
 * asking twice would be a puzzle, not a security feature.
 */
class LogisticsPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_logistics_panel_exists_with_its_own_path_and_guard(): void
    {
        $panel = Filament::getPanel('logistics');

        $this->assertSame('admin/logistics', $panel->getPath());
        $this->assertSame('admin', $panel->getAuthGuard());
    }

    public function test_the_logistics_panel_wears_the_same_theme(): void
    {
        $html = Filament::getPanel('logistics')->getTheme()->getHtml()->toHtml();

        $this->assertStringContainsString('css/filament', $html);
        $this->assertStringContainsString('build/assets/theme-', $html);
    }

    public function test_a_guest_is_sent_to_the_logistics_login(): void
    {
        $this->get('/admin/logistics')->assertRedirect('/admin/logistics/login');
    }

    public function test_a_buyer_cannot_open_the_logistics_console(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Buyer]), 'admin')
            ->get('/admin/logistics')
            ->assertForbidden();
    }

    public function test_an_operator_can_open_the_logistics_console(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin')
            ->get('/admin/logistics')
            ->assertOk();
    }
}
