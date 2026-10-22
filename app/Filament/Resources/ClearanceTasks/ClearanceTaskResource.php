<?php

namespace App\Filament\Resources\ClearanceTasks;

use App\Filament\Resources\ClearanceTasks\Tables\ClearanceTasksTable;
use App\Models\ClearanceTask;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The clearance checklist is generated from a template and completed in order.
 *
 * Create, edit and delete are all disabled: the nine tasks come from
 * ClearanceService::TEMPLATE, their order matters, and the only legitimate
 * change to one is recording what was paid and attaching the receipt. Allowing
 * a delete here would let someone quietly remove the gate-out step.
 */
class ClearanceTaskResource extends Resource
{
    protected static ?string $model = ClearanceTask::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Customs clearance';

    protected static string|UnitEnum|null $navigationGroup = 'Freight we carry';

    protected static ?string $modelLabel = 'clearance task';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ClearanceTasksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClearanceTasks::route('/'),
        ];
    }
}
