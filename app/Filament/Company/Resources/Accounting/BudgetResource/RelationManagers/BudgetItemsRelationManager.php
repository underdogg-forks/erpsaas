<?php

namespace App\Filament\Company\Resources\Accounting\BudgetResource\RelationManagers;

use App\Filament\Tables\Columns\DeferredTextInputColumn;
use App\Models\Accounting\BudgetAllocation;
use App\Models\Accounting\BudgetItem;
use App\Utilities\Currency\CurrencyConverter;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\RawJs;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\On;

class BudgetItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'budgetItems';

    protected static bool $isLazy = false;

    // Store changes that are pending
    public array $batchChanges = [];

    #[On('batch-column-changed')]
    public function handleBatchColumnChanged($data): void
    {
        // Store the changed value
        $key = "{$data['recordKey']}.{$data['name']}";
        $this->batchChanges[$key] = $data['value'];
    }

    #[On('save-batch-changes')]
    public function saveBatchChanges(): void
    {
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

    protected function calculateTotalSum(array $budgetItemIds): int
    {
        // Get all applicable periods
        $periods = BudgetAllocation::whereIn('budget_item_id', $budgetItemIds)
            ->pluck('period')
            ->unique()
            ->values()
            ->toArray();

        $total = 0;

        // Sum up each period
        foreach ($periods as $period) {
            $total += $this->calculatePeriodSum($budgetItemIds, $period);
        }

        return $total;
    }

    protected function calculatePeriodSum(array $budgetItemIds, string $period): int
    {
        // First get database values
        $dbTotal = BudgetAllocation::whereIn('budget_item_id', $budgetItemIds)
            ->where('period', $period)
            ->sum('amount');

        // Now add any batch changes
        $batchTotal = 0;
        foreach ($budgetItemIds as $itemId) {
            $key = "{$itemId}.allocations_by_period.{$period}";
            if (isset($this->batchChanges[$key])) {
                // Get the current value from batch changes
                $batchValue = CurrencyConverter::convertToCents($this->batchChanges[$key]);

                // Find if there's a current allocation in DB
                $existingAmount = BudgetAllocation::where('budget_item_id', $itemId)
                    ->where('period', $period)
                    ->first()
                    ->getRawOriginal('amount');

                // Add the difference to our running total
                $batchTotal += ($batchValue - $existingAmount);
            }
        }

        return $dbTotal + $batchTotal;
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

        return $table
            ->recordTitleAttribute('account_id')
            ->paginated(false)
            ->modifyQueryUsing(
                fn (Builder $query) => $query->with(['account', 'allocations'])
            )
            ->headerActions([
                Action::make('saveBatchChanges')
                    ->label('Save All Changes')
                    ->action('saveBatchChanges')
                    ->color('primary')
                    ->icon('heroicon-o-check-circle'),
            ])
            ->groups([
                Group::make('account.category')
                    ->titlePrefixedWithLabel(false)
                    ->collapsible(),
            ])
            ->defaultGroup('account.category')
            ->bulkActions([
                BulkAction::make('clearAllocations')
                    ->label('Clear Allocations')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) use ($periods) {
                        foreach ($records as $record) {
                            foreach ($periods as $period) {
                                $periodKey = "{$record->getKey()}.allocations_by_period.{$period}";
                                $this->batchChanges[$periodKey] = CurrencyConverter::convertCentsToFormatSimple(0);
                            }

                            $totalKey = "{$record->getKey()}.total";
                            $this->batchChanges[$totalKey] = CurrencyConverter::convertCentsToFormatSimple(0);
                        }
                    }),
            ])
            ->columns(array_merge([
                TextColumn::make('account.name')
                    ->label('Accounts')
                    ->limit(30)
                    ->searchable(),
                DeferredTextInputColumn::make('total')
                    ->label('Total')
                    ->alignRight()
                    ->mask(RawJs::make('$money($input)'))
                    ->getStateUsing(function (BudgetItem $record, DeferredTextInputColumn $column) {
                        if (isset($this->batchChanges["{$record->getKey()}.{$column->getName()}"])) {
                            return $this->batchChanges["{$record->getKey()}.{$column->getName()}"];
                        }

                        $total = $record->allocations->sum(function (BudgetAllocation $budgetAllocation) {
                            return $budgetAllocation->getRawOriginal('amount');
                        });

                        return CurrencyConverter::convertCentsToFormatSimple($total);
                    })
                    ->batchMode()
                    ->summarize(
                        Summarizer::make()
                            ->using(function (\Illuminate\Database\Query\Builder $query) {
                                $budgetItemIds = $query->pluck('id')->toArray();
                                $total = $this->calculateTotalSum($budgetItemIds);

                                return CurrencyConverter::convertCentsToFormatSimple($total);
                            })
                    ),
                IconColumn::make('disperseAction')
                    ->icon('heroicon-m-chevron-double-right')
                    ->color('primary')
                    ->label('')
                    ->default('')
                    ->action(
                        Action::make('disperse')
                            ->label('Disperse')
                            ->icon('heroicon-m-chevron-double-right')
                            ->color('primary')
                            ->iconButton()
                            ->action(function (BudgetItem $record) use ($periods) {
                                if (empty($periods)) {
                                    return;
                                }

                                $totalKey = "{$record->getKey()}.total";
                                $totalAmount = $this->batchChanges[$totalKey] ?? null;

                                if (isset($totalAmount)) {
                                    $totalCents = CurrencyConverter::convertToCents($totalAmount);
                                } else {
                                    $totalCents = $record->allocations->sum(function (BudgetAllocation $budgetAllocation) {
                                        return $budgetAllocation->getRawOriginal('amount');
                                    });
                                }

                                $numPeriods = count($periods);

                                if ($numPeriods === 0) {
                                    return;
                                }

                                if ($totalCents <= 0) {
                                    foreach ($periods as $period) {
                                        $periodKey = "{$record->getKey()}.allocations_by_period.{$period}";
                                        $this->batchChanges[$periodKey] = CurrencyConverter::convertCentsToFormatSimple(0);
                                    }

                                    return;
                                }

                                $baseAmount = floor($totalCents / $numPeriods);
                                $remainder = $totalCents - ($baseAmount * $numPeriods);

                                foreach ($periods as $index => $period) {
                                    $amount = $baseAmount + ($index === 0 ? $remainder : 0);
                                    $formattedAmount = CurrencyConverter::convertCentsToFormatSimple($amount);

                                    $periodKey = "{$record->getKey()}.allocations_by_period.{$period}";
                                    $this->batchChanges[$periodKey] = $formattedAmount;
                                }
                            }),
                    ),
            ], collect($periods)->map(
                fn ($period) => DeferredTextInputColumn::make("allocations_by_period.{$period}")
                    ->label($period)
                    ->alignRight()
                    ->mask(RawJs::make('$money($input)'))
                    ->summarize(
                        Summarizer::make()
                            ->using(function (\Illuminate\Database\Query\Builder $query) use ($period) {
                                $budgetItemIds = $query->pluck('id')->toArray();
                                $total = $this->calculatePeriodSum($budgetItemIds, $period);

                                return CurrencyConverter::convertCentsToFormatSimple($total);
                            })
                    )
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
