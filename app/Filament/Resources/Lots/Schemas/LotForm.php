<?php

namespace App\Filament\Resources\Lots\Schemas;

use App\Models\GoodsDetail;
use App\Models\VehicleDetail;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The operator's lot entry form.
 *
 * It used to ask for VIN, year, make and model on every lot, which meant the
 * only thing that could be created was a car. Goods are the other half of this
 * business — pallets, bundles, single garage-sale items — and an operator
 * literally could not file one.
 *
 * The two detail tables are edited through ->relationship() rather than being
 * flattened into the lot row. Their fields are deliberately UNPREFIXED inside
 * the section: the relationship already scopes them to the related record, and
 * writing vehicleDetail.vin there produces vehicleDetail.vehicleDetail.vin and
 * a form that saves nothing.
 */
class LotForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What is it')
                    ->schema([
                        Select::make('kind')
                            ->required()
                            ->default('vehicle')
                            ->options([
                                'vehicle' => 'Vehicle',
                                'goods' => 'Goods (pallet, bundle or single item)',
                            ])
                            // Live because the sections below swap on it.
                            ->live()
                            ->helperText('Changing this swaps which fields apply.'),

                        Select::make('provenance')
                            ->required()
                            ->default('salvage_auction')
                            ->live()
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
                            ->helperText('What the buyer sees on the board. For a vehicle this is normally the year, make and model.'),
                        Textarea::make('description')
                            ->rows(4),
                        TextInput::make('city')
                            ->required(),
                        // An array-cast column cannot round-trip through a
                        // single-line TextInput: on edit it arrives as [] and
                        // fails required, so no lot could be saved after being
                        // opened. A JSON textarea is honest about what it holds.
                        //
                        // Not required, and that is deliberate. Photos are taken
                        // at intake and uploaded at listing, so a lot created
                        // before it has been photographed legitimately has none
                        // — and required would have blocked that entire workflow
                        // rather than described it.
                        Textarea::make('photos')
                            ->rows(3)
                            ->default('[]')
                            // The column casts to a PHP array, so on edit the
                            // form would be handed an array and told it must be
                            // a JSON string. Render it as the text it is.
                            ->formatStateUsing(fn ($state) => is_array($state)
                                ? (json_encode($state, JSON_UNESCAPED_SLASHES) ?: '[]')
                                : $state)
                            ->json()
                            // ...and back again on save. The array cast
                            // json-encodes whatever it is handed, so handing it
                            // our JSON string stored it quoted and escaped —
                            // photos came back as the literal text
                            // "[\"https://…\"]" instead of a list.
                            ->dehydrateStateUsing(function ($state) {
                                if (is_array($state)) {
                                    return $state;
                                }

                                $decoded = json_decode((string) $state, true);

                                return is_array($decoded) ? $decoded : [];
                            })
                            ->helperText('JSON array of photo URLs: ["https://.../front.jpg"]. Empty until the lot is photographed.'),
                    ])
                    ->columns(2),

                Section::make('Vehicle details')
                    ->relationship(name: 'vehicleDetail', relatedModel: VehicleDetail::class)
                    ->visible(fn (Get $get): bool => $get('kind') === 'vehicle')
                    ->schema([
                        TextInput::make('vin'),
                        TextInput::make('year')->numeric(),
                        TextInput::make('make'),
                        TextInput::make('model'),
                        TextInput::make('trim'),
                        TextInput::make('odometer')->numeric(),
                        TextInput::make('damage_primary'),
                    ])
                    ->columns(2),

                Section::make('Goods details')
                    ->relationship(name: 'goodsDetail', relatedModel: GoodsDetail::class)
                    ->visible(fn (Get $get): bool => $get('kind') === 'goods')
                    ->schema([
                        TextInput::make('unit_label')
                            ->required()
                            ->helperText('Pallet, Truckload, Bed frame, Garage sale…'),
                        TextInput::make('grade'),
                        TextInput::make('manifest_count')
                            ->numeric()
                            ->helperText('How many pieces. Leave blank for a single item.'),
                        // Dimensions because air freight is charged on the
                        // space a thing takes, and a goods lot has no make and
                        // model to look a weight up against.
                        TextInput::make('length_cm')->numeric()->suffix('cm'),
                        TextInput::make('width_cm')->numeric()->suffix('cm'),
                        TextInput::make('height_cm')->numeric()->suffix('cm'),
                    ])
                    ->columns(3),

                Section::make('Freight and pricing')
                    ->schema([
                        // Freight is priced per kilo, so an unweighed lot is a
                        // lot nobody can be quoted and a page with an empty
                        // freight card. Left blank, a vehicle picks its weight
                        // up from the vehicle lookup on creation.
                        TextInput::make('weight_kg')
                            ->label('Weight (kg)')
                            ->numeric()
                            ->step('0.1')
                            ->minValue(1)
                            ->suffix('kg')
                            ->helperText('Leave blank on a vehicle to use the weight for this make and model. A goods lot must be weighed.'),
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
                    ])
                    ->columns(3),

                Section::make('Auction')
                    ->schema([
                        DateTimePicker::make('starts_at')->required(),
                        DateTimePicker::make('ends_at')->required(),
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
                    ])
                    ->columns(2),
            ]);
    }
}