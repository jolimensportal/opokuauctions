<?php

namespace App\Filament\Resources\FreightRates;

use App\Filament\Resources\FreightRates\Pages\CreateFreightRate;
use App\Filament\Resources\FreightRates\Pages\EditFreightRate;
use App\Filament\Resources\FreightRates\Pages\ListFreightRates;
use App\Filament\Resources\FreightRates\Schemas\FreightRateForm;
use App\Filament\Resources\FreightRates\Tables\FreightRatesTable;
use App\Models\FreightRate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * What the corridor costs to run, editable.
 *
 * Rates live in a table rather than in config for one reason: a forwarder
 * changes their price, and a price change must not require a deploy. One edit
 * here reaches every quote on the site.
 *
 * Create is allowed and delete is not. Adding the next corridor is a normal
 * event; removing one is not, because the figure a lot was quoted at last month
 * is evidence, and retiring the row by un-ticking Active is enough to stop it
 * quoting while keeping it.
 */
class FreightRateResource extends Resource
{
    protected static ?string $model = FreightRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Freight rates';

    protected static ?string $modelLabel = 'freight rate';

    protected static ?int $navigationSort = 25;

    public static function form(Schema $schema): Schema
    {
        return FreightRateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FreightRatesTable::configure($table);
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFreightRates::route('/'),
            'create' => CreateFreightRate::route('/create'),
            'edit' => EditFreightRate::route('/{record}/edit'),
        ];
    }
}