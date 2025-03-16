<?php

namespace App\Filament\Company\Resources\Accounting;

use App\Enums\Accounting\BudgetIntervalType;
use App\Filament\Company\Resources\Accounting\BudgetResource\Pages;
use App\Filament\Forms\Components\CustomSection;
use App\Models\Accounting\Account;
use App\Models\Accounting\Budget;
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
                            ->live(),
                        Forms\Components\Textarea::make('notes')->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Budget Items')
                    ->schema([
                        Forms\Components\Repeater::make('budgetItems')
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
                                            ->action(static fn (Forms\Set $set, Forms\Get $get, $state) => self::disperseTotalAmount($set, $get, $state))
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
                Tables\Columns\TextColumn::make('name')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('interval_type')
                    ->label('Interval')
                    ->sortable()
                    ->badge(),

                Tables\Columns\TextColumn::make('start_date')
                    ->label('Start Date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('end_date')
                    ->label('End Date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_budgeted_amount')
                    ->label('Total Budgeted')
                    ->money()
                    ->sortable()
                    ->alignEnd()
                    ->getStateUsing(fn (Budget $record) => $record->budgetItems->sum(fn ($item) => $item->allocations->sum('amount'))),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                ]),
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
        $intervalTypeEnum = BudgetIntervalType::parse($intervalType);
        $labels = [];

        while ($start->lte($end)) {
            $labels[] = match ($intervalTypeEnum) {
                BudgetIntervalType::Week => 'W' . $start->weekOfYear . ' ' . $start->year, // Example: W10 2024
                BudgetIntervalType::Month => $start->format('M'), // Example: Jan, Feb, Mar
                BudgetIntervalType::Quarter => 'Q' . $start->quarter, // Example: Q1, Q2, Q3
                BudgetIntervalType::Year => (string) $start->year, // Example: 2024, 2025
                default => '',
            };

            match ($intervalTypeEnum) {
                BudgetIntervalType::Week => $start->addWeek(),
                BudgetIntervalType::Month => $start->addMonth(),
                BudgetIntervalType::Quarter => $start->addQuarter(),
                BudgetIntervalType::Year => $start->addYear(),
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
        $intervalTypeEnum = BudgetIntervalType::parse($intervalType);
        $fields = [];

        while ($start->lte($end)) {
            $label = match ($intervalTypeEnum) {
                BudgetIntervalType::Week => 'W' . $start->weekOfYear . ' ' . $start->year, // Example: W10 2024
                BudgetIntervalType::Month => $start->format('M'), // Example: Jan, Feb, Mar
                BudgetIntervalType::Quarter => 'Q' . $start->quarter, // Example: Q1, Q2, Q3
                BudgetIntervalType::Year => (string) $start->year, // Example: 2024, 2025
                default => '',
            };

            $fields[] = Forms\Components\TextInput::make("amounts.{$label}")
                ->label($label)
                ->numeric()
                ->required();

            // Move to the next period
            match ($intervalTypeEnum) {
                BudgetIntervalType::Week => $start->addWeek(),
                BudgetIntervalType::Month => $start->addMonth(),
                BudgetIntervalType::Quarter => $start->addQuarter(),
                BudgetIntervalType::Year => $start->addYear(),
                default => null,
            };
        }

        return $fields;
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
