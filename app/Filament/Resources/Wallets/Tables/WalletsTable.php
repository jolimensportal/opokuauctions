<?php

namespace App\Filament\Resources\Wallets\Tables;

use App\Models\Wallet;
use App\Services\WalletService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Form;
use Filament\Infolists\TextEntry;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The settlement console: every buyer's money, where it is, and whether the
 * cached balances still agree with the immutable ledger.
 *
 * "Reconcile" is the action that matters. It re-derives the balance from the
 * ledger and reports drift, which is the one check that catches a money bug
 * before a customer does.
 */
class WalletsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user_id')
                    ->label('Buyer')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('currency_code')
                    ->label('Currency')
                    ->badge()
                    ->sortable(),

                // Minor units are meaningless to a human, so format on the way
                // out and never store a formatted value.
                TextColumn::make('balance_available')
                    ->label('Available')
                    ->money(fn (Wallet $record) => $record->currency_code)
                    ->sortable(),

                TextColumn::make('balance_held')
                    ->label('Held')
                    ->money(fn (Wallet $record) => $record->currency_code)
                    ->color(fn (Wallet $record) => $record->balance_held > 0 ? 'warning' : null)
                    ->sortable(),

                TextColumn::make('total')
                    ->label('Total')
                    ->state(fn (Wallet $record) => $record->balance_available + $record->balance_held)
                    ->money(fn (Wallet $record) => $record->currency_code)
                    ->sortable(query: function ($query) {
                        // Sort on the sum, not on a missing column.
                        return $query
                            ->select('wallet.*')
                            ->selectRaw('balance_available + balance_held as total');
                    })
                    ->toggleable(),

                TextColumn::make('ledger_entries_count')
                    ->label('Ledger rows')
                    ->counts('ledgerEntries')
                    ->badge()
                    ->sortable(),

                TextColumn::make('version')
                    ->label('Ver')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('currency_code')
                    ->label('Currency')
                    ->options([
                        'usd' => 'USD',
                        'ghs' => 'GHS',
                        'cad' => 'CAD',
                    ]),
            ])
            ->recordActions([
                Action::make('reconcile')
                    ->label('Reconcile')
                    ->icon('heroicon-o-shield-check')
                    ->color('primary')
                    ->action(function (Wallet $record, WalletService $walletService) {
                        $result = $walletService->reconcile(
                            $record->user_id,
                            $record->currency_code
                        );

                        Notification::make()
                            ->title($result['ok'] ? 'Wallet reconciles' : 'DRIFT DETECTED')
                            ->body(sprintf(
                                'Ledger says %s available / %s held. Wallet says %s / %s.',
                                $result['ledger']['available'],
                                $result['ledger']['held'],
                                $result['wallet']['available'],
                                $result['wallet']['held'],
                            ))
                            ->success($result['ok'])
                            ->danger(! $result['ok'])
                            ->persistent()
                            ->send();
                    })
                    // The whole point of the screen: make drift impossible to miss.
                    ->badge(fn (Wallet $record) => $record->balance_held > 0 ? 'Held' : null),

                Action::make('viewLedger')
                    ->label('Ledger')
                    ->icon('heroicon-o-list-bullet')
                    ->url(fn (Wallet $record) => \App\Filament\Resources\LedgerEntries\LedgerEntryResource::getUrl(
                        'index',
                        ['tableFilters' => ['wallet' => ['values' => [$record->id]]]]
                    )),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}