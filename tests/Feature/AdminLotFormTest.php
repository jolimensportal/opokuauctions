<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Lots\Pages\CreateLot;
use App\Filament\Resources\Lots\Pages\EditLot;
use App\Models\Lot;
use App\Models\User;
use App\Models\VehicleDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The operator's lot form, driven the way Filament actually resolves it.
 *
 * This form is a static configure() with two Section components bound by
 * ->relationship(). Filament works out the nested record paths at runtime, so a
 * form can render perfectly and still save nothing — the failure mode being
 * vehicleDetail.vehicleDetail.vin from prefixing fields that the relationship
 * has already scoped.
 *
 * Which is why this asserts on what landed in the database, not on what the
 * page looked like.
 */
class AdminLotFormTest extends TestCase
{
    use RefreshDatabase;

    private function ops(): User
    {
        return User::factory()->create(['role' => Role::Ops]);
    }

    public function test_the_vehicle_section_is_prefilled_from_the_existing_detail_row(): void
    {
        $lot = Lot::factory()->vehicle('Ford', 'F-150')->create(['title' => '2004 Ford F-150']);

        // The factory writes this row; we overwrite it rather than adding a
        // second, because a lot has exactly one vehicle detail.
        VehicleDetail::where('lot_id', $lot->id)->update([
            'vin' => '1FTFW1E50NKD00001', 'year' => 2004, 'odometer' => 210_000,
        ]);

        Livewire::actingAs($this->ops(), 'admin')
            ->test(EditLot::class, ['record' => $lot->getRouteKey()])
            ->assertOk()
            // A blank section here means ->relationship() silently failed.
            ->assertFormSet([
                'vehicleDetail.vin' => '1FTFW1E50NKD00001',
                'vehicleDetail.make' => 'Ford',
                'vehicleDetail.model' => 'F-150',
                'vehicleDetail.year' => 2004,
            ]);
    }

    public function test_editing_a_vehicle_leaves_the_detail_row_intact(): void
    {
        $lot = Lot::factory()->vehicle('Ford', 'F-150')->create();

        Livewire::actingAs($this->ops(), 'admin')
            ->test(EditLot::class, ['record' => $lot->getRouteKey()])
            ->fillForm(['title' => '2004 Ford F-150 XLT'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('lot', ['id' => $lot->id, 'title' => '2004 Ford F-150 XLT']);
        // Saving the lot must not blank the detail row.
        $this->assertDatabaseHas('vehicle_detail', [
            'lot_id' => $lot->id, 'make' => 'Ford', 'model' => 'F-150',
        ]);
    }

    public function test_an_operator_can_create_a_goods_lot(): void
    {
        Livewire::actingAs($this->ops(), 'admin')
            ->test(CreateLot::class)
            ->fillForm([
                'kind' => 'goods',
                'provenance' => 'garage_sale',
                'title' => 'Garage sale bundle — bed frame',
                'city' => 'Newark',
                'photos' => '[]',
                'weight_kg' => 5.2,
                'condition' => 'used',
                'currency_code' => 'usd',
                'starting_bid' => 40,
                'reserve_price' => 0,
                'bid_increment' => 5,
                'status' => 'UPCOMING',
                'starts_at' => now()->addDay()->toDateTimeString(),
                'ends_at' => now()->addDays(3)->toDateTimeString(),
                'goodsDetail.unit_label' => 'Bed frame',
                'goodsDetail.manifest_count' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lot = Lot::query()->where('kind', 'goods')->firstOrFail();

        $this->assertSame('Bed frame', $lot->goodsDetail->unit_label);
        $this->assertSame(1, $lot->goodsDetail->manifest_count);
        $this->assertSame('5.2', (string) $lot->weight_kg);
        // The other half of the record must not exist.
        $this->assertNull($lot->vehicleDetail);
    }

    public function test_the_goods_section_is_hidden_until_the_kind_is_goods(): void
    {
        Livewire::actingAs($this->ops(), 'admin')
            ->test(CreateLot::class)
            ->assertFormFieldHidden('goodsDetail.unit_label')
            ->assertFormFieldVisible('vehicleDetail.vin')
            ->fillForm(['kind' => 'goods'])
            ->assertFormFieldVisible('goodsDetail.unit_label')
            ->assertFormFieldHidden('vehicleDetail.vin');
    }

    public function test_a_buyer_cannot_reach_the_lot_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Buyer]), 'admin')
            ->get('/admin/lots/create')
            ->assertForbidden();
    }
}
