<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every admin screen must render for an operator.
 *
 * The storefront grew a FacetService, an FxService and a balance_due_usd
 * column; each of those is read by something behind /admin, and a screen that
 * 500s takes the whole operating side offline.
 */
class AdminSmokeTest extends TestCase
{
    use RefreshDatabase;

    public static function screenProvider(): array
    {
        return [
            'dashboard' => ['/admin'],
            'corridor dashboard' => ['/admin/corridor-dashboard'],
            'lots' => ['/admin/lots'],
            'bids' => ['/admin/bids'],
            'wallets' => ['/admin/wallets'],
            'ledger' => ['/admin/ledger-entries'],
            'clearance tasks' => ['/admin/clearance-tasks'],
            'freight rates' => ['/admin/freight-rates'],
        ];
    }

    #[DataProvider('screenProvider')]
    public function test_an_owner_can_load_every_screen(string $path): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Owner]))
            ->get($path)
            ->assertOk();
    }

    #[DataProvider('screenProvider')]
    public function test_an_ops_user_can_load_every_screen(string $path): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Ops]))
            ->get($path)
            ->assertOk();
    }

    public function test_a_buyer_is_refused_every_admin_screen(): void
    {
        $buyer = User::factory()->create(['role' => Role::Buyer]);

        foreach (array_column(self::screenProvider(), 0) as $path) {
            $this->actingAs($buyer)->get($path)->assertForbidden();
        }
    }
}
