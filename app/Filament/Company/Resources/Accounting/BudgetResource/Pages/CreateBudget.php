<?php

namespace App\Filament\Company\Resources\Accounting\BudgetResource\Pages;

use App\Enums\Accounting\BudgetIntervalType;
use App\Facades\Accounting;
use App\Filament\Company\Resources\Accounting\BudgetResource;
use App\Filament\Forms\Components\CustomSection;
use App\Models\Accounting\Account;
use App\Models\Accounting\Budget;
use App\Models\Accounting\BudgetAllocation;
use App\Models\Accounting\BudgetItem;
use App\Utilities\Currency\CurrencyConverter;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Wizard\Step;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CreateBudget extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = BudgetResource::class;

    // Add computed properties
    public function getBudgetableAccounts(): Collection
    {
        return $this->getAccountsCache('budgetable', function () {
            return Account::query()->budgetable()->get();
        });
    }

    public function getAccountsWithActuals(): Collection
    {
        $fiscalYear = $this->data['actuals_fiscal_year'] ?? null;

        if (blank($fiscalYear)) {
            return collect();
        }

        return $this->getAccountsCache("actuals_{$fiscalYear}", function () use ($fiscalYear) {
            return Account::query()
                ->budgetable()
                ->whereHas('journalEntries.transaction', function (Builder $query) use ($fiscalYear) {
                    $query->whereYear('posted_at', $fiscalYear);
                })
                ->get();
        });
    }

    public function getAccountsWithoutActuals(): Collection
    {
        $fiscalYear = $this->data['actuals_fiscal_year'] ?? null;

        if (blank($fiscalYear)) {
            return collect();
        }

        $budgetableAccounts = $this->getBudgetableAccounts();
        $accountsWithActuals = $this->getAccountsWithActuals();

        return $budgetableAccounts->whereNotIn('id', $accountsWithActuals->pluck('id'));
    }

    public function getAccountBalances(): Collection
    {
        $fiscalYear = $this->data['actuals_fiscal_year'] ?? null;

        if (blank($fiscalYear)) {
            return collect();
        }

        return $this->getAccountsCache("balances_{$fiscalYear}", function () use ($fiscalYear) {
            $fiscalYearStart = Carbon::create($fiscalYear, 1, 1)->startOfYear();
            $fiscalYearEnd = $fiscalYearStart->copy()->endOfYear();

            return Accounting::getAccountBalances(
                $fiscalYearStart->toDateString(),
                $fiscalYearEnd->toDateString(),
                $this->getBudgetableAccounts()->pluck('id')->toArray()
            )->get();
        });
    }

    // Cache helper to avoid duplicate queries
    private array $accountsCache = [];

    private function getAccountsCache(string $key, callable $callback): Collection
    {
        if (! isset($this->accountsCache[$key])) {
            $this->accountsCache[$key] = $callback();
        }

        return $this->accountsCache[$key];
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
                                ->afterStateUpdated(function (Forms\Set $set) {
                                    // Clear the cache when the fiscal year changes
                                    $this->accountsCache = [];

                                    // Get all accounts without actuals
                                    $accountIdsWithoutActuals = $this->getAccountsWithoutActuals()->pluck('id')->toArray();

                                    // Set exclude_accounts_without_actuals to true by default
                                    $set('exclude_accounts_without_actuals', true);

                                    // Update the selected_accounts field to exclude accounts without actuals
                                    $set('selected_accounts', $accountIdsWithoutActuals);
                                })
                                ->visible(fn (Forms\Get $get) => $get('prefill_method') === 'actuals'),
                        ])->visible(fn (Forms\Get $get) => $get('prefill_data') === true),

                    CustomSection::make('Account Selection')
                        ->contained(false)
                        ->schema([
                            Forms\Components\Checkbox::make('exclude_accounts_without_actuals')
                                ->label('Exclude all accounts without actuals')
                                ->helperText(function () {
                                    $count = $this->getAccountsWithoutActuals()->count();

                                    return "Will exclude {$count} accounts without transaction data in the selected fiscal year";
                                })
                                ->default(true)
                                ->live()
                                ->afterStateUpdated(function (Forms\Set $set, $state) {
                                    if ($state) {
                                        // When checked, select all accounts without actuals
                                        $accountsWithoutActuals = $this->getAccountsWithoutActuals()->pluck('id')->toArray();
                                        $set('selected_accounts', $accountsWithoutActuals);
                                    } else {
                                        // When unchecked, clear the selection
                                        $set('selected_accounts', []);
                                    }
                                }),

                            Forms\Components\CheckboxList::make('selected_accounts')
                                ->label('Select Accounts to Exclude')
                                ->options(function () {
                                    // Get all budgetable accounts
                                    return $this->getBudgetableAccounts()->pluck('name', 'id')->toArray();
                                })
                                ->descriptions(function (Forms\Components\CheckboxList $component) {
                                    $fiscalYear = $this->data['actuals_fiscal_year'] ?? null;

                                    if (blank($fiscalYear)) {
                                        return [];
                                    }

                                    $accountIds = array_keys($component->getOptions());
                                    $descriptions = [];

                                    if (empty($accountIds)) {
                                        return [];
                                    }

                                    // Get account balances
                                    $accountBalances = $this->getAccountBalances()->keyBy('id');

                                    // Get accounts with actuals
                                    $accountsWithActuals = $this->getAccountsWithActuals()->pluck('id')->toArray();

                                    // Process all accounts
                                    foreach ($accountIds as $accountId) {
                                        $balance = $accountBalances[$accountId] ?? null;
                                        $hasActuals = in_array($accountId, $accountsWithActuals);

                                        if ($balance && $hasActuals) {
                                            // Calculate net movement
                                            $netMovement = Accounting::calculateNetMovementByCategory(
                                                $balance->category,
                                                $balance->total_debit ?? 0,
                                                $balance->total_credit ?? 0
                                            );

                                            // Format the amount for display
                                            $formattedAmount = CurrencyConverter::formatCentsToMoney($netMovement);
                                            $descriptions[$accountId] = "{$formattedAmount} in {$fiscalYear}";
                                        } else {
                                            $descriptions[$accountId] = "No transactions in {$fiscalYear}";
                                        }
                                    }

                                    return $descriptions;
                                })
                                ->columns(2) // Display in two columns
                                ->searchable() // Allow searching for accounts
                                ->bulkToggleable() // Enable "Select All" / "Deselect All"
                                ->selectAllAction(fn (Action $action) => $action->label('Exclude all accounts'))
                                ->deselectAllAction(fn (Action $action) => $action->label('Include all accounts'))
                                ->afterStateUpdated(function (Forms\Set $set, $state) {
                                    // Get all accounts without actuals
                                    $accountsWithoutActuals = $this->getAccountsWithoutActuals()->pluck('id')->toArray();

                                    // Check if all accounts without actuals are in the selected accounts
                                    $allAccountsWithoutActualsSelected = empty(array_diff($accountsWithoutActuals, $state));

                                    // Update the exclude_accounts_without_actuals checkbox state
                                    $set('exclude_accounts_without_actuals', $allAccountsWithoutActualsSelected);
                                }),
                        ])
                        ->visible(function () {
                            // Only show when using actuals with valid fiscal year AND accounts without transactions exist
                            $prefillMethod = $this->data['prefill_method'] ?? null;

                            if ($prefillMethod !== 'actuals' || blank($this->data['actuals_fiscal_year'] ?? null)) {
                                return false;
                            }

                            return $this->getAccountsWithoutActuals()->isNotEmpty();
                        }),

                    Forms\Components\Textarea::make('notes')
                        ->label('Notes')
                        ->columnSpanFull(),
                ]),
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
