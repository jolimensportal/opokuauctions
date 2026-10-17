<?php

namespace App\Filament\Resources\LedgerEntries\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Models\Wallet;
use Filament\Forms\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The append-only record of every balance movement. Deliberately read-only:
 * there is no create, edit or delete, because the ledger is the source of
 * truth and rewriting it would destroy the only way to detect drift.
 */
class LedgerEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'DEPOSIT' => 'success',
                        'HOLD' => 'warning',
                        'RELEASE' => 'info',
                        'CAPTURE' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('amount')
                    // Inflows green, outflows red, so a long ledger can be
                    // scanned by eye.
                    ->color(fn (int $state) => $state >= 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn (int $state, $record) => sprintf(
                        '%s%d minor units',
                        $state >= 0 ? '+' : '-',
                        abs($state),
                    ))
                    ->sortable(),

                TextColumn::make('balance_after')
                    ->label('Balance after')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('reference_type')
                    ->label('Reason')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('reference_id')
                    ->label('Ref')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('note')
                    ->placeholder('—')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Added so the wallet console can deep-link straight into one
                // buyer's ledger.
                SelectFilter::make('wallet')
                    ->label('Buyer')
                    ->relationship('wallet', 'user_id')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        'DEPOSIT' => 'Deposit',
                        'HOLD' => 'Hold',
                        'RELEASE' => 'Release',
                        'CAPTURE' => 'Capture',
                    ]),
            ])
            ->recordActions([])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
