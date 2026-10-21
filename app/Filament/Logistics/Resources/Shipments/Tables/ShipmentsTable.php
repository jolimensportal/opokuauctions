<?php

namespace App\Filament\Logistics\Resources\Shipments\Tables;

use Filament\Actions\EditAction;
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
                EditAction::make(),
            ])
            ->defaultSort('eta');
    }
}
