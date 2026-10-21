<?php

namespace App\Filament\Logistics\Resources\Shipments;

use App\Filament\Logistics\Resources\Shipments\Pages\CreateShipment;
use App\Filament\Logistics\Resources\Shipments\Pages\EditShipment;
use App\Filament\Logistics\Resources\Shipments\Pages\ListShipments;
use App\Filament\Logistics\Resources\Shipments\Schemas\ShipmentForm;
use App\Filament\Logistics\Resources\Shipments\Tables\ShipmentsTable;
use App\Models\Shipment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * One container crossing the corridor.
 *
 * There was no resource for this at all, so a container could only be created
 * by a seeder. Everything about freight hangs off a consignment being a record
 * somebody can open, list and change.
 */
class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = 'Containers';

    protected static string|UnitEnum|null $navigationGroup = 'Freight we carry';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'container';

    protected static ?string $pluralModelLabel = 'containers';

    public static function form(Schema $schema): Schema
    {
        return ShipmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShipmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShipments::route('/'),
            'create' => CreateShipment::route('/create'),
            'edit' => EditShipment::route('/{record}/edit'),
        ];
    }
}
