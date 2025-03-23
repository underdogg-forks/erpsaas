<?php

namespace App\Filament\Company\Resources\Accounting\BudgetResource\Pages;

use App\Enums\Accounting\BudgetIntervalType;
use App\Filament\Company\Resources\Accounting\BudgetResource;
use App\Filament\Forms\Components\CustomTableRepeater;
use App\Models\Accounting\Budget;
use App\Models\Accounting\BudgetAllocation;
use App\Models\Accounting\BudgetItem;
use Awcodes\TableRepeater\Header;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\RawJs;
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

    public function getMaxContentWidth(): MaxWidth | string | null
    {
        return 'max-w-8xl';
    }

    public function form(Form $form): Form
    {
        /** @var Budget $budget */
        $budget = $this->record;
        $periods = $budget->getPeriods();

        $headers = [
            Header::make('Account')
                ->label('Account')
                ->width('200px'),
        ];

        foreach ($periods as $period) {
            $headers[] = Header::make($period)
                ->label($period)
                ->width('120px')
                ->align(Alignment::Center);
        }

        return $form->schema([
            Forms\Components\Section::make('Budget Details')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required(),
                    Forms\Components\Select::make('interval_type')
                        ->disabled(), // Can't change interval type after creation
                    Forms\Components\DatePicker::make('start_date')
                        ->disabled(),
                    Forms\Components\DatePicker::make('end_date')
                        ->disabled(),
                    Forms\Components\Textarea::make('notes'),
                ]),

            Forms\Components\Section::make('Budget Allocations')
                ->schema([
                    CustomTableRepeater::make('budgetItems')
                        ->relationship()
                        ->headers($headers)
                        ->schema([
                            Forms\Components\Placeholder::make('account')
                                ->hiddenLabel()
                                ->content(fn ($record) => $record->account->name ?? ''),

                            // Create a field for each period
                            ...collect($periods)->map(function ($period) {
                                return Forms\Components\TextInput::make("allocations.{$period}")
                                    ->mask(RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->numeric()
                                    ->afterStateHydrated(function ($component, $state, $record) use ($period) {
                                        // Find the allocation for this period
                                        $allocation = $record->allocations->firstWhere('period', $period);
                                        $component->state($allocation ? $allocation->amount : 0);
                                    })
                                    ->dehydrated(false); // We'll handle saving manually
                            })->toArray(),
                        ])
                        ->spreadsheet()
                        ->itemLabel(fn ($record) => $record->account->name ?? 'Budget Item')
                        ->deletable(false)
                        ->reorderable(false)
                        ->addable(false) // Don't allow adding new budget items
                        ->columnSpanFull(),
                ]),
        ]);
    }

    //    protected function mutateFormDataBeforeFill(array $data): array
    //    {
    //        /** @var Budget $budget */
    //        $budget = $this->record;
    //
    //        $data['budgetItems'] = $budget->budgetItems->map(function (BudgetItem $budgetItem) {
    //            return [
    //                'id' => $budgetItem->id,
    //                'account_id' => $budgetItem->account_id,
    //                'total_amount' => $budgetItem->allocations->sum('amount'), // Calculate total dynamically
    //                'amounts' => $budgetItem->allocations->mapWithKeys(static function (BudgetAllocation $allocation) {
    //                    return [$allocation->period => $allocation->amount]; // Use the correct period label
    //                })->toArray(),
    //            ];
    //        })->toArray();
    //
    //        return $data;
    //    }

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
            BudgetIntervalType::Month => $startDate->copy()->endOfMonth(),
            BudgetIntervalType::Quarter => $startDate->copy()->endOfQuarter(),
            BudgetIntervalType::Year => $startDate->copy()->endOfYear(),
        };
    }
}
