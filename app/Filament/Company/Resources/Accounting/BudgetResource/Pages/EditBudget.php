<?php

namespace App\Filament\Company\Resources\Accounting\BudgetResource\Pages;

use App\Enums\Accounting\BudgetIntervalType;
use App\Filament\Company\Resources\Accounting\BudgetResource;
use App\Models\Accounting\Budget;
use App\Models\Accounting\BudgetAllocation;
use App\Models\Accounting\BudgetItem;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class EditBudget extends EditRecord
{
    protected static string $resource = BudgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Budget $budget */
        $budget = $this->record;

        $data['budgetItems'] = $budget->budgetItems->map(function (BudgetItem $budgetItem) {
            return [
                'id' => $budgetItem->id,
                'account_id' => $budgetItem->account_id,
                'total_amount' => $budgetItem->allocations->sum('amount'), // Calculate total dynamically
                'amounts' => $budgetItem->allocations->mapWithKeys(static function (BudgetAllocation $allocation) {
                    return [$allocation->period => $allocation->amount]; // Use the correct period label
                })->toArray(),
            ];
        })->toArray();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Budget $budget */
        $budget = $record;

        $budget->update([
            'name' => $data['name'],
            'interval_type' => $data['interval_type'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'notes' => $data['notes'] ?? null,
        ]);

        $budgetItemIds = [];

        foreach ($data['budgetItems'] as $itemData) {
            /** @var BudgetItem $budgetItem */
            $budgetItem = $budget->budgetItems()->updateOrCreate(
                ['id' => $itemData['id'] ?? null],
                ['account_id' => $itemData['account_id']]
            );

            $budgetItemIds[] = $budgetItem->id;

            $budgetItem->allocations()->delete();

            $allocationStart = Carbon::parse($data['start_date']);

            foreach ($itemData['amounts'] as $periodLabel => $amount) {
                $allocationEnd = self::calculateEndDate($allocationStart, BudgetIntervalType::parse($data['interval_type']));

                // Recreate allocations
                $budgetItem->allocations()->create([
                    'period' => $periodLabel,
                    'interval_type' => $data['interval_type'],
                    'start_date' => $allocationStart->toDateString(),
                    'end_date' => $allocationEnd->toDateString(),
                    'amount' => $amount,
                ]);

                $allocationStart = $allocationEnd->addDay();
            }
        }

        $budget->budgetItems()->whereNotIn('id', $budgetItemIds)->delete();

        return $budget;
    }

    private static function calculateEndDate(Carbon $startDate, BudgetIntervalType $intervalType): Carbon
    {
        return match ($intervalType) {
            BudgetIntervalType::Week => $startDate->copy()->endOfWeek(),
            BudgetIntervalType::Month => $startDate->copy()->endOfMonth(),
            BudgetIntervalType::Quarter => $startDate->copy()->endOfQuarter(),
            BudgetIntervalType::Year => $startDate->copy()->endOfYear(),
        };
    }
}
