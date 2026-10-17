<?php

namespace App\Filament\Resources\Lots\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LotForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('vin'),
                TextInput::make('year')
                    ->required()
                    ->numeric(),
                TextInput::make('make')
                    ->required(),
                TextInput::make('model')
                    ->required(),
                TextInput::make('trim'),

                // Freight is priced per kilo, so an unweighed lot is a lot
                // nobody can be quoted and a page with an empty freight card.
                // Left blank, the lot picks its weight up from the vehicle
                // lookup on creation; type over it when the car in front of you
                // is not the standard one.
                TextInput::make('curb_weight_kg')
                    ->label('Curb weight (kg)')
                    ->numeric()
                    ->step('0.1')
                    ->minValue(1)
                    ->suffix('kg')
                    ->helperText('Leave blank to use the weight for this make and model. Type a number to override it.'),
                TextInput::make('city')
                    ->required(),
                TextInput::make('photos')
                    ->required()
                    ->default('[]'),
                TextInput::make('condition')
                    ->required()
                    ->default('unknown'),
                TextInput::make('odometer')
                    ->numeric(),
                TextInput::make('damage_primary'),
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
