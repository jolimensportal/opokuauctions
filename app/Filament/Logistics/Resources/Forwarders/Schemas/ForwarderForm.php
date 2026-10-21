<?php

namespace App\Filament\Logistics\Resources\Forwarders\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ForwarderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The carrier')
                    ->schema([
                        TextInput::make('name')->required(),
                        TextInput::make('contact_name')->label('Contact name'),
                        TextInput::make('contact_phone')->label('Contact phone')
                            ->tel(),
                        TextInput::make('contact_email')->label('Contact email')
                            ->email(),
                    ])
                    ->columns(2),
            ]);
    }
}
