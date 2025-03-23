<?php

namespace App\Filament\Company\Resources\Accounting\BudgetResource\RelationManagers;

use App\Models\Accounting\BudgetAllocation;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class BudgetItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'budgetItems';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('account_id')
            ->columns([
                Tables\Columns\TextColumn::make('account.name')
                    ->label('Account')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('allocations_sum_amount')
                    ->label('Total Allocations')
                    ->sortable()
                    ->alignEnd()
                    ->sum('allocations', 'amount')
                    ->money(divideBy: 100),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('editAllocations')
                    ->label('Edit Allocations')
                    ->icon('heroicon-o-pencil')
                    ->modalHeading(fn ($record) => "Edit Allocations for {$record->account->name}")
                    ->modalWidth('xl')
                    ->form(function ($record) {
                        $fields = [];

                        // Get allocations ordered by date
                        $allocations = $record->allocations()->orderBy('start_date')->get();

                        foreach ($allocations as $allocation) {
                            $fields[] = TextInput::make("allocations.{$allocation->id}")
                                ->label($allocation->period)
                                ->numeric()
                                ->default(function () use ($allocation) {
                                    return $allocation->amount;
                                })
                                ->prefix('$')
                                ->live(debounce: 500)
                                ->afterStateUpdated(function (TextInput $component, $state) {
                                    // Format the value as needed
                                    $component->state(number_format($state, 2, '.', ''));
                                });
                        }

                        return [
                            Grid::make()
                                ->schema($fields)
                                ->columns(3),
                        ];
                    })
                    ->action(function (array $data, $record) {
                        foreach ($data['allocations'] as $allocationId => $amount) {
                            BudgetAllocation::find($allocationId)->update([
                                'amount' => $amount,
                            ]);
                        }
                    }),
            ])
            ->bulkActions([
                //                Tables\Actions\BulkActionGroup::make([
                //                    Tables\Actions\DeleteBulkAction::make(),
                //                ]),
            ]);
    }
}
