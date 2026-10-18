<?php

namespace App\Filament\Resources\FreightRates\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FreightRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // The business key, not a label. 'na-tema' is how the quote
                // service finds this row, so it is a fixed token rather than
                // something anyone types freely.
                TextInput::make('lane')
                    ->required()
                    ->maxLength(40)
                    ->helperText('Lower case, e.g. na-tema. This is how a quote finds the rate.'),

                // The one non-integer figure in the pricing path. Everything
                // else is cents, and it stays that way.
                TextInput::make('usd_per_kg')
                    ->required()
                    ->numeric()
                    ->step('0.0001')
                    ->prefix('$')
                    ->helperText('Per kilogram. The only rate in this form that is not whole cents.'),

                // The forwarder's minimum consignment charge. It floors the
                // freight line alone: insurance and pickup are real charges on
                // a small car and must not be swallowed by the floor.
                // No currency prefix on the cents fields. A "$" next to a raw
                // 25000 reads as "$25000" and gets typed as 250, which stores
                // USD 2.50. The unit belongs in the label instead.
                TextInput::make('min_charge_cents')
                    ->label('Container minimum (cents)')
                    ->required()
                    ->numeric()
                    ->suffix('cents')
                    // This used to be a per-lot floor, which meant a 200 g
                    // headlamp shipped beside a car was billed the full USD
                    // 250 for itself. The freight for a container is computed
                    // once, floored once, then split by weight — so this number
                    // is paid once per container, no matter how many lots are
                    // aboard. Leaving the old wording here would have an
                    // operator "fixing" a rate that is behaving correctly.
                    ->helperText('Forwarder minimum consignment charge, charged once per container. USD 250.00 is 25000. Split across the lots aboard by weight, so a light item beside a car pays cents.'),

                // Flat per car. Cover is priced on the vehicle, not the kilos.
                TextInput::make('insurance_cents')
                    ->label('Insurance per car (cents)')
                    ->required()
                    ->numeric()
                    ->suffix('cents'),
                TextInput::make('pickup_cents')
                    ->label('Pickup (cents)')
                    ->required()
                    ->numeric()
                    ->suffix('cents')
                    ->helperText('Charged only when the buyer asks us to collect from the port.'),

                // Un-tick rather than delete: the row is the record of what a
                // lot was quoted at last month.
                Toggle::make('active')
                    ->required()
                    ->default(true)
                    ->helperText('Only an active rate quotes. Un-tick to retire one without losing it.'),
            ]);
    }
}