<?php

namespace App\Filament\Resources\Lots\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LotForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // The vehicle-only inputs moved to vehicle_detail. This form is
                // temporarily reduced to the shared columns so admin keeps
                // working; it is rebuilt kind-aware later in this branch.
                Select::make('kind')
                    ->required()
                    ->default('vehicle')
                    ->options([
                        'vehicle' => 'Vehicle',
                        'goods' => 'Goods (pallet, bundle or single item)',
                    ]),
                Select::make('provenance')
                    ->required()
                    ->default('salvage_auction')
                    ->options([
                        'salvage_auction' => 'Salvage auction',
                        'garage_sale' => 'Garage sale',
                        'repo' => 'Foreclosure',
                        'liquidation' => 'Liquidation',
                        'overstock' => 'Overstock / returns',
                        'estate' => 'Estate',
                        'trade_in' => 'Trade-in',
                    ]),
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->helperText('What the buyer sees on the board.'),
                Textarea::make('description')
                    ->rows(3),

                // Freight is priced per kilo, so an unweighed lot is a lot
                // nobody can be quoted and a page with an empty freight card.
                // Left blank, a vehicle picks its weight up from the vehicle
                // lookup on creation; type over it when the car in front of you
                // is not the standard one. A goods lot must be weighed.
                TextInput::make('weight_kg')
                    ->label('Weight (kg)')
                    ->numeric()
                    ->step('0.1')
                    ->minValue(1)
                    ->suffix('kg')
                    ->helperText('Leave blank on a vehicle to use the weight for this make and model. A goods lot must be weighed.'),
                TextInput::make('city')
                    ->required(),
                TextInput::make('photos')
                    ->required()
                    ->default('[]'),
                TextInput::make('condition')
                    ->required()
                    ->default('unknown'),
                TextInput::make('currency_code')
                    ->required()
                    ->default('usd'),
                TextInput::make('starting_bid')
                    ->required()
                    ->numeric(),
                TextInput::make('reserve_price')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->prefix('$'),
                TextInput::make('bid_increment')
                    ->required()
                    ->numeric(),
                DateTimePicker::make('starts_at')
                    ->required(),
                DateTimePicker::make('ends_at')
                    ->required(),
                TextInput::make('status')
                    ->required()
                    ->default('UPCOMING'),
                TextInput::make('current_bid')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('highest_bidder_id'),
                TextInput::make('bid_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('extensions')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
