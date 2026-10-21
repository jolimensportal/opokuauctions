<?php

namespace App\Filament\Logistics\Resources\Forwarders\Pages;

use App\Filament\Logistics\Resources\Forwarders\ForwarderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListForwarders extends ListRecords
{
    protected static string $resource = ForwarderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
