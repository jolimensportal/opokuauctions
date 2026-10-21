<?php

namespace App\Filament\Logistics\Resources\Forwarders;

use App\Filament\Logistics\Resources\Forwarders\Pages\CreateForwarder;
use App\Filament\Logistics\Resources\Forwarders\Pages\EditForwarder;
use App\Filament\Logistics\Resources\Forwarders\Pages\ListForwarders;
use App\Filament\Logistics\Resources\Forwarders\Schemas\ForwarderForm;
use App\Filament\Logistics\Resources\Forwarders\Tables\ForwardersTable;
use App\Models\Forwarder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The carriers we book containers with.
 *
 * A forwarder is a record, not an account: they never log in, the console
 * books on their behalf. There were none, which is why nothing could be
 * booked or costed.
 */
class ForwarderResource extends Resource
{
    protected static ?string $model = Forwarder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Forwarders';

    protected static string|UnitEnum|null $navigationGroup = 'Freight we carry';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'forwarder';

    protected static ?string $pluralModelLabel = 'forwarders';

    public static function form(Schema $schema): Schema
    {
        return ForwarderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ForwardersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListForwarders::route('/'),
            'create' => CreateForwarder::route('/create'),
            'edit' => EditForwarder::route('/{record}/edit'),
        ];
    }
}
