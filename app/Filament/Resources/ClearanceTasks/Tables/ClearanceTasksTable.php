<?php

namespace App\Filament\Resources\ClearanceTasks\Tables;

use App\Models\ClearanceTask;
use App\Services\ClearanceService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The Tema customs checklist, as ops will actually use it.
 *
 * Two things are deliberately visible rather than buried:
 *
 *  - **Cost**, in money, on every row that moves it. This is what feeds the
 *    landed cost quoted to buyers.
 *  - **Unevidenced money tasks in red.** A completed duty task with no receipt
 *    attached is not done; it is a customs problem three weeks from now, and
 *    the operator should see it while they can still fix it.
 */
class ClearanceTasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shipment_id')
                    ->label('Container')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('type')
                    ->label('Task')
                    ->formatStateUsing(fn (string $state) => ClearanceService::labelFor($state))
                    ->description(fn (ClearanceTask $record) => $record->type)
                    ->sortable(['position']),

                TextColumn::make('position')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('cost')
                    ->label('Amount paid')
                    ->money('USD')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('receipt_path')
                    ->label('Receipt')
                    ->formatStateUsing(fn (?string $state) => $state ? 'attached' : null)
                    ->badge()
                    ->color(fn (ClearanceTask $record) => $record->receipt_path ? 'success' : null)
                    ->placeholder('missing')
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('completed_at')
                    ->label('Done')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // The warning column: loud, because it is the thing that
                // quietly becomes a customs dispute.
                IconColumn::make('unevidenced')
                    ->label('Evidence')
                    ->boolean()
                    ->getStateUsing(fn (ClearanceTask $record) => $record->isUnevidenced())
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check')
                    ->color(fn (ClearanceTask $record) => $record->isUnevidenced() ? 'danger' : 'success')
                    ->tooltip(fn (ClearanceTask $record) => $record->isUnevidenced()
                        ? 'Money task marked complete with no receipt attached'
                        : 'Evidenced')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'complete' => 'Complete',
                        'blocked' => 'Blocked',
                    ]),
            ])
            ->recordActions([
                Action::make('complete')
                    ->label('Mark done')
                    ->icon('heroicon-o-check')
                    ->visible(fn (ClearanceTask $record) => ! $record->isComplete())
                    // Money tasks must be given an amount and a receipt before
                    // they can be called done. The form is conditional rather
                    // than optional so it cannot be skipped by accident.
                    ->form(fn (ClearanceTask $record) => [
                        ...(ClearanceService::costsMoney($record->type)
                            ? [
                                Forms\Components\TextInput::make('cost')
                                    ->label('Amount paid (USD cents)')
                                    ->numeric()
                                    ->required()
                                    ->helperText('Integer cents. 25000 = $250.00'),
                                Forms\Components\TextInput::make('receipt_path')
                                    ->label('Receipt reference')
                                    ->required()
                                    ->helperText('Where the receipt is filed. Strongly advised: required in practice.'),
                            ]
                            : []),
                        Forms\Components\Textarea::make('notes')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->action(function (ClearanceTask $record, array $data) {
                        app(\App\Services\ClearanceService::class)->completeTask(
                            $record->id,
                            auth()->user(),
                            isset($data['cost']) ? (int) $data['cost'] : null,
                            $data['receipt_path'] ?? null,
                            $data['notes'] ?? null,
                        );
                    }),
            ])
            ->defaultSort('position');
    }
}