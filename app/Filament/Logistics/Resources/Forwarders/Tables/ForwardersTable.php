<?php

namespace App\Filament\Logistics\Resources\Forwarders\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ForwardersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('contact_name')->label('Contact'),
                TextColumn::make('contact_phone')->label('Phone'),
                TextColumn::make('contact_email')->label('Email')->searchable(),
                TextColumn::make('shipments_count')
                    ->counts('shipments')
                    ->label('Containers'),
            ])
            ->actions([
                EditAction::make(),
            ]);
    }
}
