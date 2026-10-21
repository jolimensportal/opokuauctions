<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin panel must wear our design system.
 *
 * It did not: the panel loaded Filament's own stylesheet and nothing of ours,
 * so every utility class written in an admin blade was inert. A custom theme
 * is the only supported way to change that, and it has to be a Vite build
 * rather than a static file, because the tokens live in the same place as the
 * storefront's.
 */
class AdminThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_panel_uses_our_vite_theme(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertSame('resources/css/filament/theme.css', $panel->getViteTheme());
    }

    public function test_the_admin_primary_colour_is_the_shops_brick(): void
    {
        $colours = Filament::getPanel('admin')->getColors();

        $this->assertArrayHasKey('primary', $colours);

        // The console's primary is the storefront's own brick-500, not
        // Filament's amber and not a generated ramp that merely looks close.
        // One brand, one red: if these two ever drift, the shop and the
        // console stop looking like the same product.
        $this->assertSame('oklch(0.565 0.205 27)', $colours['primary'][500]);
    }

    public function test_the_admin_page_still_renders_with_the_theme(): void
    {
        $operator = User::factory()->create(['role' => Role::Ops]);

        $this->actingAs($operator, 'admin')
            ->get('/admin')
            ->assertOk();
    }
}
