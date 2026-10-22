<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The console and the customer portal are one product.
 *
 * The component classes lived inside the admin theme file, so the portal
 * could not use them without copying them, and a copy would drift. One
 * shared file, loaded by both.
 */
class SharedDesignSystemTest extends TestCase
{
    public function test_the_shared_design_system_exists(): void
    {
        $this->assertFileExists(resource_path('css/opoku-ui.css'));
    }

    public function test_the_shared_design_system_holds_the_components_both_surfaces_use(): void
    {
        $css = (string) file_get_contents(resource_path('css/opoku-ui.css'));

        foreach (['.card', '.tile', '.chip', '.action-row', '.banner', '.timeline', '.bar'] as $selector) {
            $this->assertStringContainsString($selector, $css, "{$selector} is missing from the shared system");
        }
    }

    public function test_both_surfaces_load_the_shared_system(): void
    {
        $admin = (string) file_get_contents(resource_path('css/filament/theme.css'));
        $portal = (string) file_get_contents(resource_path('css/portal.css'));

        $this->assertStringContainsString('opoku-ui.css', $admin);
        $this->assertStringContainsString('opoku-ui.css', $portal);
    }
}
