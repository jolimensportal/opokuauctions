<?php

namespace App\Filament\Resources\Wallets\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class WalletForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('user_id')
                    ->required(),
                TextInput::make('currency_code')
                    ->required(),
                TextInput::make('balance_available')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('balance_held')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('version')
                    ->required()
                    ->numeric()
                    ->default(1),
            ]);
    }
}
