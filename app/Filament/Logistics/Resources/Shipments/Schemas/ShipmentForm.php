<?php

namespace App\Filament\Logistics\Resources\Shipments\Schemas;

use App\Models\Forwarder;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * What an operator knows about a container when they open one.
 *
 * Money is in cents, because every other money column in this platform is in
 * cents and a form that asks for dollars is a form that will be wrong.
 */
class ShipmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The container')
                    ->schema([
                        TextInput::make('container_no')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->label('Container number'),
                        TextInput::make('vessel')->label('Vessel'),
                        TextInput::make('voyage')->label('Voyage'),
                        TextInput::make('booking_ref')->label('Booking reference'),
                        TextInput::make('bill_of_lading')->label('Bill of lading'),
                    ])
                    ->columns(2),

                Section::make('The route')
                    ->schema([
                        TextInput::make('seaport_origin')->label('From'),
                        TextInput::make('seaport_destination')->label('To'),
                        DateTimePicker::make('eta')->label('ETA'),
                        TextInput::make('tracking_source')
                            ->label('Tracking source')
                            ->helperText('Which feed this container is tracked by, if any. Nothing polls it yet — see the deferred register.'),
                    ])
                    ->columns(2),

                Section::make('The carrier and the money')
                    ->schema([
                        Select::make('forwarder_id')
                            ->label('Forwarder')
                            ->options(fn (): array => Forwarder::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable(),
                        Select::make('status')
                            ->required()
                            ->default('BOOKING')
                            ->options([
                                'BOOKING' => 'Booking',
                                'LOADED' => 'Loaded',
                                'DEPARTED' => 'Departed',
                                'SAILING' => 'Sailing',
                                'ARRIVED' => 'Arrived at Tema',
                                'CLEARED' => 'Cleared',
                            ]),
                        TextInput::make('freight_cost')
                            ->label('Freight (cents)')
                            ->numeric()
                            ->default(0),
                        TextInput::make('insurance_cost')
                            ->label('Insurance (cents)')
                            ->numeric()
                            ->default(0),
                    ])
                    ->columns(2),
            ]);
    }
}
