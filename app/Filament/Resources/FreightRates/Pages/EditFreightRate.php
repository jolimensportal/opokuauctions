<?php

namespace App\Filament\Resources\FreightRates\Pages;

use App\Filament\Resources\FreightRates\FreightRateResource;
use Filament\Resources\Pages\EditRecord;

class EditFreightRate extends EditRecord
{
    protected static string $resource = FreightRateResource::class;

    /**
     * No delete action, matching FreightRateResource::canDelete().
     *
     * A rate is retired by un-ticking Active. Deleting it would destroy the
     * record of what a lot was quoted before the change.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}