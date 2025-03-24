<?php

namespace App\Filament\Company\Resources\Accounting\BudgetResource\RelationManagers;

use App\Filament\Tables\Columns\DeferredTextInputColumn;
use App\Models\Accounting\BudgetAllocation;
use App\Models\Accounting\BudgetItem;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class BudgetItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'budgetItems';

    protected static bool $isLazy = false;

    // Store changes that are pending
    public array $batchChanges = [];

    // Listen for events from the custom column

    #[On('batch-column-changed')]
    public function handleBatchColumnChanged($data): void
    {
        ray($data);
        // Store the changed value
        $key = "{$data['recordKey']}.{$data['name']}";
        $this->batchChanges[$key] = $data['value'];
    }

    #[On('save-batch-changes')]
    public function saveBatchChanges(): void
    {
        ray('Saving batch changes');
        foreach ($this->batchChanges as $key => $value) {
            // Parse the composite key
            [$recordKey, $column] = explode('.', $key, 2);

            // Extract period from the column name (e.g., "allocations_by_period.2023-Q1")
            preg_match('/allocations_by_period\.(.+)/', $column, $matches);
            $period = $matches[1] ?? null;

            if (! $period) {
                continue;
            }

            // Find the record
            $record = BudgetItem::find($recordKey);
            if (! $record) {
                continue;
            }

            // Update the allocation
            $allocation = $record->allocations->firstWhere('period', $period);
            if ($allocation) {
                $allocation->update(['amount' => $value]);
            } else {
                $record->allocations()->create([
                    'period' => $period,
                    'amount' => $value,
                    // Add other required fields
                ]);
            }
        }

        // Clear the batch changes
        $this->batchChanges = [];

        // Notify the user
        Notification::make()
            ->title('Budget allocations updated')
            ->success()
            ->send();
    }

    public function table(Table $table): Table
    {
        $budget = $this->getOwnerRecord();

        // Get distinct periods for this budget
        $periods = BudgetAllocation::query()
            ->join('budget_items', 'budget_allocations.budget_item_id', '=', 'budget_items.id')
            ->where('budget_items.budget_id', $budget->id)
            ->orderBy('start_date')
            ->pluck('period')
            ->unique()
            ->values()
            ->toArray();

        ray($this->batchChanges);

        return $table
            ->recordTitleAttribute('account_id')
            ->paginated(false)
            ->modifyQueryUsing(
                fn ($query) => $query->with(['account', 'allocations'])
            )
            ->headerActions([
                Action::make('saveBatchChanges')
                    ->label('Save All Changes')
                    ->action('saveBatchChanges')
                    ->color('primary')
                    ->icon('heroicon-o-check-circle'),
            ])
            ->columns(array_merge([
                TextColumn::make('account.name')
                    ->label('Accounts')
                    ->sortable()
                    ->searchable(),
            ], collect($periods)->map(
                fn ($period) => DeferredTextInputColumn::make("allocations_by_period.{$period}")
                    ->label($period)
                    ->getStateUsing(function ($record, DeferredTextInputColumn $column) use ($period) {
                        $key = "{$record->getKey()}.{$column->getName()}";

                        // Check if batch change exists
                        if (isset($this->batchChanges[$key])) {
                            return $this->batchChanges[$key];
                        }

                        return $record->allocations->firstWhere('period', $period)?->amount;
                    })
                    ->batchMode(),
            )->all()));
    }
}
