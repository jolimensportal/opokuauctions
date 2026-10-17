<?php

namespace App\Filament\Resources\Bids\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BidForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('lot_id')
                    ->required(),
                TextInput::make('bidder_id')
                    ->required(),
                TextInput::make('bidder_email')
                    ->email()
                    ->required(),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                TextInput::make('currency_code')
                    ->required()
                    ->default('usd'),
                TextInput::make('status')
                    ->required()
                    ->default('ACCEPTED'),
                Toggle::make('caused_extension')
                    ->required(),
                TextInput::make('idempotency_key'),
            ]);
    }
}
