<?php

namespace App\Filament\Logistics\Resources\Shipments\Pages;

use App\Filament\Logistics\Resources\Shipments\ShipmentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateShipment extends CreateRecord
{
    protected static string $resource = ShipmentResource::class;

    /**
     * The shipment id is a string, not a sequence, and the seeder's ids look
     * like `shp_demo_01`. A container number is unique and is what an
     * operator will read out on the phone, so it makes the best id.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['id'] = $data['id'] ?? 'shp_'.Str::lower(Str::random(12));

        return $data;
    }
}
