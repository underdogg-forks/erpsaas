<?php

namespace App\Filament\Company\Resources\Accounting\BudgetResource\Pages;

use App\Filament\Company\Resources\Accounting\BudgetResource;
use App\Models\Accounting\Budget;
use App\Models\Accounting\BudgetItem;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CreateBudget extends CreateRecord
{
    protected static string $resource = BudgetResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Budget $budget */
        $budget = Budget::create([
            'name' => $data['name'],
            'interval_type' => $data['interval_type'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($data['budgetItems'] as $itemData) {
            /** @var BudgetItem $budgetItem */
            $budgetItem = $budget->budgetItems()->create([
                'account_id' => $itemData['account_id'],
            ]);

            $allocationStart = Carbon::parse($data['start_date']);

            foreach ($itemData['amounts'] as $periodLabel => $amount) {
                $allocationEnd = self::calculateEndDate($allocationStart, $data['interval_type']);

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

        return $budget;
    }

    private static function calculateEndDate(Carbon $startDate, string $intervalType): Carbon
    {
        return match ($intervalType) {
            'quarter' => $startDate->copy()->addMonths(2)->endOfMonth(),
            'year' => $startDate->copy()->endOfYear(),
            default => $startDate->copy()->endOfMonth(),
        };
    }
}
