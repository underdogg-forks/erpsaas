<?php

namespace App\Filament\Company\Resources\Accounting;

use App\Filament\Company\Resources\Accounting\BudgetResource\Pages;
use App\Filament\Forms\Components\CustomSection;
use App\Models\Accounting\Account;
use App\Models\Accounting\Budget;
use App\Models\Accounting\BudgetItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class BudgetResource extends Resource
{
    protected static ?string $model = Budget::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Budget Details')
                    ->columns()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('interval_type')
                            ->label('Budget Interval')
                            ->options([
                                'day' => 'Daily',
                                'week' => 'Weekly',
                                'month' => 'Monthly',
                                'quarter' => 'Quarterly',
                                'year' => 'Yearly',
                            ])
                            ->default('month')
                            ->required()
                            ->live(),
                        Forms\Components\DatePicker::make('start_date')
                            ->required()
                            ->default(now()->startOfYear())
                            ->live(),
                        Forms\Components\DatePicker::make('end_date')
                            ->required()
                            ->default(now()->endOfYear())
                            ->live(),
                        Forms\Components\Textarea::make('notes')->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Budget Items')
                    ->schema([
                        Forms\Components\Repeater::make('budgetItems')
                            ->relationship()
                            ->columns(4)
                            ->hiddenLabel()
                            ->schema([
                                Forms\Components\Select::make('account_id')
                                    ->label('Account')
                                    ->options(Account::query()->pluck('name', 'id'))
                                    ->searchable()
                                    ->columnSpan(1)
                                    ->required(),

                                Forms\Components\TextInput::make('total_amount')
                                    ->label('Total Amount')
                                    ->numeric()
                                    ->required()
                                    ->columnSpan(1)
                                    ->suffixAction(
                                        Forms\Components\Actions\Action::make('disperse')
                                            ->label('Disperse')
                                            ->icon('heroicon-m-bars-arrow-down')
                                            ->color('primary')
                                            ->action(fn (Forms\Set $set, Forms\Get $get, $state) => self::disperseTotalAmount($set, $get, $state))
                                    ),

                                CustomSection::make('Budget Allocations')
                                    ->contained(false)
                                    ->columns(4)
                                    ->schema(static fn (Forms\Get $get) => self::getAllocationFields($get('../../start_date'), $get('../../end_date'), $get('../../interval_type'))),
                            ])
                            ->defaultItems(1)
                            ->addActionLabel('Add Budget Item'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Disperses the total amount across the budget items based on the selected interval.
     */
    private static function disperseTotalAmount(Forms\Set $set, Forms\Get $get, float $totalAmount): void
    {
        $startDate = $get('../../start_date');
        $endDate = $get('../../end_date');
        $intervalType = $get('../../interval_type');

        if (! $startDate || ! $endDate || ! $intervalType || $totalAmount <= 0) {
            return;
        }

        // Generate labels based on interval type (must match `getAllocationFields()`)
        $labels = self::generateFormattedLabels($startDate, $endDate, $intervalType);
        $numPeriods = count($labels);

        if ($numPeriods === 0) {
            return;
        }

        // Calculate base allocation and handle rounding
        $baseAmount = floor($totalAmount / $numPeriods);
        $remainder = $totalAmount - ($baseAmount * $numPeriods);

        // Assign amounts to the correct fields using labels
        foreach ($labels as $index => $label) {
            $amount = $baseAmount + ($index === 0 ? $remainder : 0);
            $set("amounts.{$label}", $amount); // Now correctly assigns to the right field
        }
    }

    /**
     * Generates formatted labels for the budget allocation fields based on the selected interval type.
     */
    private static function generateFormattedLabels(string $startDate, string $endDate, string $intervalType): array
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $labels = [];

        while ($start->lte($end)) {
            $labels[] = match ($intervalType) {
                'month' => $start->format('M'), // Example: Jan, Feb, Mar
                'quarter' => 'Q' . $start->quarter, // Example: Q1, Q2, Q3
                'year' => (string) $start->year, // Example: 2024, 2025
                default => '',
            };

            match ($intervalType) {
                'month' => $start->addMonth(),
                'quarter' => $start->addQuarter(),
                'year' => $start->addYear(),
                default => null,
            };
        }

        return $labels;
    }

    private static function getAllocationFields(?string $startDate, ?string $endDate, ?string $intervalType): array
    {
        if (! $startDate || ! $endDate || ! $intervalType) {
            return [];
        }

        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $fields = [];

        while ($start->lte($end)) {
            $label = match ($intervalType) {
                'month' => $start->format('M'), // Example: Jan, Feb, Mar
                'quarter' => 'Q' . $start->quarter, // Example: Q1, Q2, Q3
                'year' => (string) $start->year, // Example: 2024, 2025
                default => '',
            };

            $fields[] = Forms\Components\TextInput::make("amounts.{$label}")
                ->label($label)
                ->numeric()
                ->required();

            // Move to the next period
            match ($intervalType) {
                'month' => $start->addMonth(),
                'quarter' => $start->addQuarter(),
                'year' => $start->addYear(),
                default => null,
            };
        }

        return $fields;
    }

    /**
     * Generates an array of interval labels (e.g., Jan 2024, Q1 2024, etc.).
     */
    private static function generateIntervals(string $startDate, string $endDate, string $intervalType): array
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $intervals = [];

        while ($start->lte($end)) {
            if ($intervalType === 'month') {
                $intervals[] = $start->format('M Y'); // Example: Jan 2024
                $start->addMonth();
            } elseif ($intervalType === 'quarter') {
                $intervals[] = 'Q' . $start->quarter . ' ' . $start->year; // Example: Q1 2024
                $start->addQuarter();
            } elseif ($intervalType === 'year') {
                $intervals[] = $start->year; // Example: 2024
                $start->addYear();
            }
        }

        return $intervals;
    }

    /**
     * Saves budget allocations correctly in `budget_allocations` table.
     */
    public static function saveBudgetAllocations(BudgetItem $record, array $data): void
    {
        $record->update($data);

        $intervals = self::generateIntervals($data['start_date'], $data['end_date'], $data['interval_type']);

        foreach ($intervals as $interval) {
            $record->allocations()->updateOrCreate(
                ['period' => $interval],
                [
                    'interval_type' => $data['interval_type'],
                    'start_date' => Carbon::parse($interval)->startOfMonth(),
                    'end_date' => Carbon::parse($interval)->endOfMonth(),
                    'amount' => $data['allocations'][$interval] ?? 0,
                ]
            );
        }
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBudgets::route('/'),
            'create' => Pages\CreateBudget::route('/create'),
            'view' => Pages\ViewBudget::route('/{record}'),
            'edit' => Pages\EditBudget::route('/{record}/edit'),
        ];
    }
}
