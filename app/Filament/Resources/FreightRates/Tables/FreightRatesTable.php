<?php

namespace App\Filament\Resources\FreightRates\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FreightRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('lane')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('usd_per_kg')
                    // Rendered as money here even though it is a rate: an
                    // operator comparing two corridors reads 0.32, not 0.3200.
                    ->formatStateUsing(fn ($state) => '$'.number_format((float) $state, 4))
                    ->sortable(),
                TextColumn::make('min_charge_cents')
                    ->label('Container min')
                    ->tooltip('The forwarder minimum consignment charge. Paid once per container, then split across the lots aboard by weight.')
                    ->formatStateUsing(fn ($state) => '$'.number_format((int) $state / 100, 2))
                    ->sortable(),
                TextColumn::make('insurance_cents')
                    ->label('Insurance / car')
                    ->formatStateUsing(fn ($state) => '$'.number_format((int) $state / 100, 2))
                    ->sortable(),
                TextColumn::make('pickup_cents')
                    ->label('Pickup')
                    ->formatStateUsing(fn ($state) => '$'.number_format((int) $state / 100, 2))
                    ->sortable(),
                IconColumn::make('active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Last corrected')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}