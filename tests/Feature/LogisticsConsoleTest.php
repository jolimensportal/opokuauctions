<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The freight console lives inside the operations console.
 *
 * It used to be a second Filament panel, which gave each console its own
 * navigation and left an operator with no way from one to the other: the
 * freight console was only reachable by typing its URL, because Filament's
 * navigation belongs to the panel. One panel with grouped navigation gives
 * the same separation — the freight groups sit beside the marketplace ones —
 * and nothing is hidden.
 */
class LogisticsConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_is_one_console_and_the_freight_screen_is_a_page_in_it(): void
    {
        $this->assertNull(Filament::getPanel('logistics'), 'the freight console is a page, not a second panel');

        $panel = Filament::getPanel('admin');

        $this->assertSame('admin', $panel->getPath());
        $this->assertSame('admin', $panel->getAuthGuard());
    }

    public function test_the_freight_console_is_reachable_at_its_own_url(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin')
            ->get('/admin/logistics')
            ->assertOk()
            ->assertSee('Logistics')
            ->assertSee('Required action');
    }

    public function test_the_freight_console_is_listed_in_the_console_navigation(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin')
            ->get('/admin')
            ->assertOk()
            // The link an operator uses to find it. Without this the freight
            // console is invisible to anybody who does not know the URL.
            ->assertSee('href="'.url('/admin/logistics').'"', false);
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/admin/logistics')->assertRedirect('/admin/login');
    }

    public function test_a_buyer_cannot_open_the_freight_console(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Buyer]), 'admin')
            ->get('/admin/logistics')
            ->assertForbidden();
    }

    public function test_an_operator_can_open_the_freight_console(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]), 'admin')
            ->get('/admin/logistics')
            ->assertOk();
    }

    public function test_the_console_wears_our_design_system(): void
    {
        $html = Filament::getPanel('admin')->getTheme()->getHtml()->toHtml();

        $this->assertStringContainsString('css/filament', $html);
        $this->assertStringContainsString('build/assets/theme-', $html);
    }
}
