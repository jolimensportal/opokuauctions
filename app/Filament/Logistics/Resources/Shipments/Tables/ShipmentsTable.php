<?php

namespace App\Filament\Logistics\Resources\Shipments\Tables;

use App\Models\Shipment;
use App\Services\ShipmentMilestoneRecorder;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShipmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('container_no')
                    ->label('Container')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('vessel')->searchable(),
                TextColumn::make('seaport_origin')->label('From'),
                TextColumn::make('seaport_destination')->label('To'),
                TextColumn::make('forwarder.name')->label('Forwarder'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('eta')->dateTime()->sortable(),
                TextColumn::make('freight_cost')
                    ->label('Freight')
                    ->money('USD')
                    ->sortable(),
            ])
            ->actions([
                // The one action that moves freight. It writes the milestone
                // and the container's state together, so a container can
                // never look booked while it is at sea.
                Action::make('recordMilestone')
                    ->label('Record a milestone')
                    ->icon(Heroicon::OutlinedMapPin)
                    ->schema([
                        Select::make('type')
                            ->required()
                            ->label('What happened')
                            ->options([
                                'LOADED' => 'Loaded',
                                'DEPARTED' => 'Departed',
                                'SAILING' => 'Sailing',
                                'ARRIVED' => 'Arrived at Tema',
                                'CLEARED' => 'Cleared',
                            ]),
                        DateTimePicker::make('occurred_at')
                            ->label('When')
                            ->default(now())
                            ->required(),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->helperText('What a person would want to know in two weeks.'),
                    ])
                    ->action(function (array $data, Shipment $record): void {
                        app(ShipmentMilestoneRecorder::class)->record(
                            shipment: $record,
                            type: $data['type'],
                            occurredAt: $data['occurred_at'],
                            notes: $data['notes'] ?? null,
                            recordedBy: auth('admin')->user(),
                        );
                    }),
                EditAction::make(),
            ])
            ->defaultSort('eta');
    }
}
