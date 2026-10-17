<?php

namespace App\Filament\Resources\FreightRates\Pages;

use App\Filament\Resources\FreightRates\FreightRateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFreightRates extends ListRecords
{
    protected static string $resource = FreightRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}