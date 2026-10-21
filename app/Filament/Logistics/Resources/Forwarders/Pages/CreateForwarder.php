<?php

namespace App\Filament\Logistics\Resources\Forwarders\Pages;

use App\Filament\Logistics\Resources\Forwarders\ForwarderResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateForwarder extends CreateRecord
{
    protected static string $resource = ForwarderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['id'] = 'fwd_'.Str::lower(Str::random(10));

        return $data;
    }
}
