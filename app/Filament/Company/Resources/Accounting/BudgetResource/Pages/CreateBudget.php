<?php

namespace App\Filament\Company\Resources\Accounting\BudgetResource\Pages;

use App\Enums\Accounting\BudgetIntervalType;
use App\Facades\Accounting;
use App\Filament\Company\Resources\Accounting\BudgetResource;
use App\Filament\Forms\Components\LinearWizard;
use App\Models\Accounting\Account;
use App\Models\Accounting\Budget;
use App\Models\Accounting\BudgetAllocation;
use App\Models\Accounting\BudgetItem;
use App\Utilities\Currency\CurrencyConverter;
use Filament\Actions\ActionGroup;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CreateBudget extends CreateRecord
{
    protected static string $resource = BudgetResource::class;

    public function getStartStep(): int
    {
        return 1;
    }

    public function form(Form $form): Form
    {
        return parent::form($form)
            ->schema([
                LinearWizard::make($this->getSteps())
                    ->startOnStep($this->getStartStep())
                    ->cancelAction($this->getCancelFormAction())
                    ->submitAction($this->getSubmitFormAction()->label('Next'))
                    ->skippable($this->hasSkippableSteps()),
            ])
            ->columns(null);
    }

    /**
     * @return array<\Filament\Actions\Action | ActionGroup>
     */
    public function getFormActions(): array
    {
        return [];
    }

    protected function hasSkippableSteps(): bool
    {
        return false;
    }

    public function getSteps(): array
    {
        return [
            Step::make('General Information')
                ->icon('heroicon-o-document-text')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('interval_type')
                        ->label('Budget Interval')
                        ->options(BudgetIntervalType::class)
                        ->default(BudgetIntervalType::Month->value)
                        ->required()
                        ->live(),
                    Forms\Components\DatePicker::make('start_date')
                        ->required()
                        ->default(now()->startOfYear())
                        ->live(),
                    Forms\Components\DatePicker::make('end_date')
                        ->required()
                        ->default(now()->endOfYear())
                        ->live()
                        ->disabled(static fn (Forms\Get $get) => blank($get('start_date')))
                        ->minDate(fn (Forms\Get $get) => match (BudgetIntervalType::parse($get('interval_type'))) {
                            BudgetIntervalType::Month => Carbon::parse($get('start_date'))->addMonth(),
                            BudgetIntervalType::Quarter => Carbon::parse($get('start_date'))->addQuarter(),
                            BudgetIntervalType::Year => Carbon::parse($get('start_date'))->addYear(),
                            default => Carbon::parse($get('start_date'))->addDay(),
                        })
                        ->maxDate(fn (Forms\Get $get) => Carbon::parse($get('start_date'))->endOfYear()),
                ]),

            Step::make('Budget Setup & Settings')
                ->icon('heroicon-o-cog-6-tooth')
                ->schema([
                    // Prefill configuration
                    Forms\Components\Toggle::make('prefill_data')
                        ->label('Prefill Data')
                        ->helperText('Enable this option to prefill the budget with historical data')
                        ->default(false)
                        ->live(),

                    Forms\Components\Grid::make(1)
                        ->schema([
                            Forms\Components\Select::make('prefill_method')
                                ->label('Prefill Method')
                                ->options([
                                    'previous_budget' => 'Copy from a previous budget',
                                    'actuals' => 'Use historical actuals',
                                ])
                                ->live()
                                ->required(),

                            // If user selects to copy a previous budget
                            Forms\Components\Select::make('source_budget_id')
                                ->label('Source Budget')
                                ->options(fn () => Budget::query()
                                    ->orderByDesc('end_date')
                                    ->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                                ->visible(fn (Forms\Get $get) => $get('prefill_method') === 'previous_budget'),

                            // If user selects to use historical actuals
                            Forms\Components\Select::make('actuals_fiscal_year')
                                ->label('Reference Fiscal Year')
                                ->options(function () {
                                    $options = [];
                                    $company = auth()->user()->currentCompany;
                                    $earliestDate = Carbon::parse(Accounting::getEarliestTransactionDate());
                                    $fiscalYearStartCurrent = Carbon::parse($company->locale->fiscalYearStartDate());

                                    for ($year = $fiscalYearStartCurrent->year; $year >= $earliestDate->year; $year--) {
                                        $options[$year] = $year;
                                    }

                                    return $options;
                                })
                                ->required()
                                ->live()
                                ->visible(fn (Forms\Get $get) => $get('prefill_method') === 'actuals'),
                        ])->visible(fn (Forms\Get $get) => $get('prefill_data') === true),

                    Forms\Components\Textarea::make('notes')
                        ->label('Notes')
                        ->columnSpanFull(),
                ]),

            Step::make('Modify Budget Structure')
                ->icon('heroicon-o-adjustments-horizontal')
                ->schema([
                    Forms\Components\CheckboxList::make('selected_accounts')
                        ->label('Select Accounts to Exclude')
                        ->options(function (Forms\Get $get) {
                            $fiscalYear = $get('actuals_fiscal_year');

                            // Get all budgetable accounts
                            $allAccounts = Account::query()->budgetable()->pluck('name', 'id')->toArray();

                            // Get accounts that have actuals for the selected fiscal year
                            $accountsWithActuals = Account::query()
                                ->budgetable()
                                ->whereHas('journalEntries.transaction', function (Builder $query) use ($fiscalYear) {
                                    $query->whereYear('posted_at', $fiscalYear);
                                })
                                ->pluck('name', 'id')
                                ->toArray();

                            return $allAccounts + $accountsWithActuals; // Merge both sets
                        })
                        ->columns(2) // Display in two columns
                        ->searchable() // Allow searching for accounts
                        ->bulkToggleable() // Enable "Select All" / "Deselect All"
                        ->selectAllAction(
                            fn (Action $action, Forms\Get $get) => $action
                                ->label('Remove all items without past actuals (' .
                                    Account::query()->budgetable()->whereDoesntHave('journalEntries.transaction', function (Builder $query) use ($get) {
                                        $query->whereYear('posted_at', $get('actuals_fiscal_year'));
                                    })->count() . ' lines)')
                        )
                        ->disableOptionWhen(fn (string $value, Forms\Get $get) => in_array(
                            $value,
                            Account::query()->budgetable()->whereHas('journalEntries.transaction', function (Builder $query) use ($get) {
                                $query->whereYear('posted_at', Carbon::parse($get('actuals_fiscal_year'))->year);
                            })->pluck('id')->toArray()
                        ))
                        ->visible(fn (Forms\Get $get) => $get('prefill_method') === 'actuals'),
                ])
                ->visible(function (Forms\Get $get) {
                    $prefillMethod = $get('prefill_method');

                    if ($prefillMethod !== 'actuals' || blank($get('actuals_fiscal_year'))) {
                        return false;
                    }

                    return Account::query()->budgetable()->whereDoesntHave('journalEntries.transaction', function (Builder $query) use ($get) {
                        $query->whereYear('posted_at', $get('actuals_fiscal_year'));
                    })->exists();
                }),
        ];
    }

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

        $selectedAccounts = $data['selected_accounts'] ?? [];

        $accountsToInclude = Account::query()
            ->budgetable()
            ->whereNotIn('id', $selectedAccounts)
            ->get();

        foreach ($accountsToInclude as $account) {
            /** @var BudgetItem $budgetItem */
            $budgetItem = $budget->budgetItems()->create([
                'account_id' => $account->id,
            ]);

            $allocationStart = Carbon::parse($data['start_date']);

            // Determine amounts based on the prefill method
            $amounts = match ($data['prefill_method'] ?? null) {
                'actuals' => $this->getAmountsFromActuals($account, $data['actuals_fiscal_year'], BudgetIntervalType::parse($data['interval_type'])),
                'previous_budget' => $this->getAmountsFromPreviousBudget($account, $data['source_budget_id'], BudgetIntervalType::parse($data['interval_type'])),
                default => $this->generateZeroAmounts($data['start_date'], $data['end_date'], BudgetIntervalType::parse($data['interval_type'])),
            };

            foreach ($amounts as $periodLabel => $amount) {
                $allocationEnd = self::calculateEndDate($allocationStart, BudgetIntervalType::parse($data['interval_type']));

                $budgetItem->allocations()->create([
                    'period' => $periodLabel,
                    'interval_type' => $data['interval_type'],
                    'start_date' => $allocationStart->toDateString(),
                    'end_date' => $allocationEnd->toDateString(),
                    'amount' => CurrencyConverter::convertCentsToFloat($amount),
                ]);

                $allocationStart = $allocationEnd->addDay();
            }
        }

        return $budget;
    }

    private function getAmountsFromActuals(Account $account, int $fiscalYear, BudgetIntervalType $intervalType): array
    {
        // Determine the fiscal year start and end dates
        $fiscalYearStart = Carbon::create($fiscalYear, 1, 1)->startOfYear();
        $fiscalYearEnd = $fiscalYearStart->copy()->endOfYear();

        $netMovement = Accounting::getNetMovement($account, $fiscalYearStart->toDateString(), $fiscalYearEnd->toDateString());

        return $this->distributeAmountAcrossPeriods($netMovement->getAmount(), $fiscalYearStart, $fiscalYearEnd, $intervalType);
    }

    private function distributeAmountAcrossPeriods(int $totalAmountInCents, Carbon $startDate, Carbon $endDate, BudgetIntervalType $intervalType): array
    {
        $amounts = [];
        $periods = [];

        // Generate period labels based on interval type
        $currentPeriod = $startDate->copy();
        while ($currentPeriod->lte($endDate)) {
            $periods[] = $this->determinePeriod($currentPeriod, $intervalType);
            $currentPeriod->addUnit($intervalType->value);
        }

        // Evenly distribute total amount across periods
        $periodCount = count($periods);

        if ($periodCount === 0) {
            return $amounts;
        }

        $baseAmount = intdiv($totalAmountInCents, $periodCount); // Floor division to get the base amount in cents
        $remainder = $totalAmountInCents % $periodCount; // Remaining cents to distribute

        foreach ($periods as $index => $period) {
            $amounts[$period] = $baseAmount + ($index < $remainder ? 1 : 0); // Distribute remainder cents evenly
        }

        return $amounts;
    }

    private function getAmountsFromPreviousBudget(Account $account, int $sourceBudgetId, BudgetIntervalType $intervalType): array
    {
        $amounts = [];

        $previousAllocations = BudgetAllocation::query()
            ->whereHas('budgetItem', fn ($query) => $query->where('account_id', $account->id)->where('budget_id', $sourceBudgetId))
            ->get();

        foreach ($previousAllocations as $allocation) {
            $amounts[$allocation->period] = $allocation->getRawOriginal('amount');
        }

        return $amounts;
    }

    private function generateZeroAmounts(string $startDate, string $endDate, BudgetIntervalType $intervalType): array
    {
        $amounts = [];

        $currentPeriod = Carbon::parse($startDate);
        while ($currentPeriod->lte(Carbon::parse($endDate))) {
            $period = $this->determinePeriod($currentPeriod, $intervalType);
            $amounts[$period] = 0;
            $currentPeriod->addUnit($intervalType->value);
        }

        return $amounts;
    }

    private function determinePeriod(Carbon $date, BudgetIntervalType $intervalType): string
    {
        return match ($intervalType) {
            BudgetIntervalType::Month => $date->format('F Y'),
            BudgetIntervalType::Quarter => 'Q' . $date->quarter . ' ' . $date->year,
            BudgetIntervalType::Year => (string) $date->year,
            default => $date->format('Y-m-d'),
        };
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
