<?php

namespace App\Filament\Company\Resources\Accounting\BudgetResource\RelationManagers;

use App\Models\Accounting\BudgetItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;

class BudgetItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'budgetItems';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        $budget = $this->getOwnerRecord();

        // Get distinct periods for this budget
        $periods = \App\Models\Accounting\BudgetAllocation::query()
            ->join('budget_items', 'budget_allocations.budget_item_id', '=', 'budget_items.id')
            ->where('budget_items.budget_id', $budget->id)
            ->orderBy('start_date')
            ->pluck('period')
            ->unique()
            ->values()
            ->toArray();

        return $table
            ->recordTitleAttribute('account_id')
            ->paginated(false)
            ->modifyQueryUsing(
                fn ($query) => $query->with(['account', 'allocations'])
            )
            ->columns(array_merge([
                TextColumn::make('account.name')
                    ->label('Accounts')
                    ->sortable()
                    ->searchable(),
            ], collect($periods)->map(
                fn ($period) => TextInputColumn::make("allocations_by_period.{$period}")
                    ->label($period)
                    ->getStateUsing(
                        fn ($record) => $record->allocations->firstWhere('period', $period)?->amount
                    )
                    ->updateStateUsing(function (BudgetItem $record, $state) use ($period) {
                        $allocation = $record->allocations->firstWhere('period', $period);

                        if ($allocation) {
                            $allocation->update(['amount' => $state]);
                        }
                    })
            )->all()));
    }
}
