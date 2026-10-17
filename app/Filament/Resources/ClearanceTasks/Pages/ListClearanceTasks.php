<?php

namespace App\Filament\Resources\ClearanceTasks\Pages;

use App\Filament\Resources\ClearanceTasks\ClearanceTaskResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClearanceTasks extends ListRecords
{
    protected static string $resource = ClearanceTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
