<?php

namespace App\Filament\Company\Resources\Accounting\BudgetResource\RelationManagers;

use App\Filament\Tables\Columns\DeferredTextInputColumn;
use App\Models\Accounting\Budget;
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

class BudgetItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'budgetItems';

    protected static bool $isLazy = false;

    public array $batchChanges = [];

    public function handleBatchColumnChanged($data): void
    {
        $key = "{$data['recordKey']}.{$data['name']}";
        $this->batchChanges[$key] = $data['value'];
    }

    public function saveBatchChanges(): void
    {
        foreach ($this->batchChanges as $key => $value) {
            [$recordKey, $column] = explode('.', $key, 2);

            preg_match('/amount_(.+)/', $column, $matches);
            $period = str_replace('_', ' ', $matches[1] ?? '');

            if (! $period) {
                continue;
            }

            $record = BudgetItem::find($recordKey);
            if (! $record) {
                continue;
            }

            $allocation = $record->allocations->firstWhere('period', $period);
            if ($allocation) {
                $allocation->update(['amount' => $value]);
            } else {
                $record->allocations()->create([
                    'period' => $period,
                    'amount' => $value,
                ]);
            }
        }

        $this->batchChanges = [];

        Notification::make()
            ->title('Budget allocations updated')
            ->success()
            ->send();
    }

    protected function calculateTotalSum(array $budgetItemIds): int
    {
        $periods = BudgetAllocation::whereIn('budget_item_id', $budgetItemIds)
            ->pluck('period')
            ->unique()
            ->values()
            ->toArray();

        $total = 0;
        foreach ($periods as $period) {
            $total += $this->calculatePeriodSum($budgetItemIds, $period);
        }

        return $total;
    }

    protected function calculatePeriodSum(array $budgetItemIds, string $period): int
    {
        $dbTotal = BudgetAllocation::whereIn('budget_item_id', $budgetItemIds)
            ->where('period', $period)
            ->sum('amount');

        $batchTotal = 0;
        foreach ($budgetItemIds as $itemId) {
            $key = "{$itemId}.amount_" . str_replace(['-', '.', ' '], '_', $period);
            if (isset($this->batchChanges[$key])) {
                $batchValue = CurrencyConverter::convertToCents($this->batchChanges[$key]);
                $existingAmount = BudgetAllocation::where('budget_item_id', $itemId)
                    ->where('period', $period)
                    ->first()
                    ?->getRawOriginal('amount') ?? 0;

                $batchTotal += ($batchValue - $existingAmount);
            }
        }

        return $dbTotal + $batchTotal;
    }

    public function table(Table $table): Table
    {
        /** @var Budget $budget */
        $budget = $this->getOwnerRecord();
        $periods = $budget->getPeriods();

        return $table
            ->recordTitleAttribute('account_id')
            ->paginated(false)
            ->heading(null)
            ->modifyQueryUsing(function (Builder $query) use ($periods) {
                $query->select('budget_items.*')
                    ->leftJoin('budget_allocations', 'budget_allocations.budget_item_id', '=', 'budget_items.id');

                foreach ($periods as $period) {
                    $alias = 'amount_' . str_replace(['-', '.', ' '], '_', $period);
                    $query->selectRaw(
                        "SUM(CASE WHEN budget_allocations.period = ? THEN budget_allocations.amount ELSE 0 END) as {$alias}",
                        [$period]
                    );
                }

                return $query->groupBy('budget_items.id');
            })
            ->groups([
                Group::make('account.category')
                    ->titlePrefixedWithLabel(false)
                    ->collapsible(),
            ])
            ->recordClasses(['budget-items-relation-manager'])
            ->defaultGroup('account.category')
            ->headerActions([
                Action::make('saveBatchChanges')
                    ->label('Save all changes')
                    ->action('saveBatchChanges')
                    ->color('primary'),
            ])
            ->columns([
                TextColumn::make('account.name')
                    ->label('Account')
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

                        $total = $record->allocations->sum(
                            fn (BudgetAllocation $allocation) => $allocation->getRawOriginal('amount')
                        );

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
                    ->tooltip('Disperse total across periods')
                    ->action(
                        Action::make('disperse')
                            ->label('Disperse')
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
                                        $periodKey = "{$record->getKey()}.amount_" . str_replace(['-', '.', ' '], '_', $period);
                                        $this->batchChanges[$periodKey] = CurrencyConverter::convertCentsToFormatSimple(0);
                                    }

                                    return;
                                }

                                $baseAmount = floor($totalCents / $numPeriods);
                                $remainder = $totalCents - ($baseAmount * $numPeriods);

                                foreach ($periods as $index => $period) {
                                    $amount = $baseAmount + ($index === 0 ? $remainder : 0);
                                    $formattedAmount = CurrencyConverter::convertCentsToFormatSimple($amount);

                                    $periodKey = "{$record->getKey()}." . 'amount_' . str_replace(['-', '.', ' '], '_', $period);
                                    $this->batchChanges[$periodKey] = $formattedAmount;
                                }
                            }),
                    ),
                ...array_map(function (string $period) {
                    $alias = 'amount_' . str_replace(['-', '.', ' '], '_', $period); // Also replace space

                    return DeferredTextInputColumn::make($alias)
                        ->label($period)
                        ->alignRight()
                        ->batchMode()
                        ->mask(RawJs::make('$money($input)'))
                        ->getStateUsing(function ($record) use ($alias) {
                            $key = "{$record->getKey()}.{$alias}";

                            return $this->batchChanges[$key] ?? CurrencyConverter::convertCentsToFormatSimple($record->{$alias} ?? 0);
                        })
                        ->summarize(
                            Summarizer::make()
                                ->using(function (\Illuminate\Database\Query\Builder $query) use ($period) {
                                    $budgetItemIds = $query->pluck('id')->toArray();
                                    $total = $this->calculatePeriodSum($budgetItemIds, $period);

                                    return CurrencyConverter::convertCentsToFormatSimple($total);
                                })
                        );
                }, $periods),
            ])
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
                                $periodKey = "{$record->getKey()}.amount_" . str_replace(['-', '.', ' '], '_', $period);
                                $this->batchChanges[$periodKey] = CurrencyConverter::convertCentsToFormatSimple(0);
                            }
                        }
                    }),
            ]);
    }
}
