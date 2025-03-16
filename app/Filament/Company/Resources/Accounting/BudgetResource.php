<?php

namespace App\Filament\Company\Resources\Accounting;

use App\Filament\Company\Resources\Accounting\BudgetResource\Pages;
use App\Models\Accounting\Account;
use App\Models\Accounting\Budget;
use Awcodes\TableRepeater\Components\TableRepeater;
use Awcodes\TableRepeater\Header;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BudgetResource extends Resource
{
    protected static ?string $model = Budget::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Budget Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\DatePicker::make('start_date')->required(),
                            Forms\Components\DatePicker::make('end_date')->required(),
                        ]),

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

                        Forms\Components\Textarea::make('notes')->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Budget Items')
                    ->schema([
                        TableRepeater::make('budgetItems')
                            ->relationship()
                            ->saveRelationshipsUsing(null)
                            ->dehydrated(true)
                            ->headers(fn (Forms\Get $get) => self::getHeaders($get('interval_type')))
                            ->schema([
                                Forms\Components\Select::make('account_id')
                                    ->label('Account')
                                    ->options(Account::query()->pluck('name', 'id'))
                                    ->searchable()
                                    ->required(),

                                Forms\Components\Grid::make(2)->schema([
                                    Forms\Components\DatePicker::make('start_date')->required(),
                                    Forms\Components\DatePicker::make('end_date')->required(),
                                ]),

                                Forms\Components\TextInput::make('amount')
                                    ->numeric()
                                    ->suffix('USD')
                                    ->required(),
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

    private static function getHeaders(?string $intervalType): array
    {
        $headers = [
            Header::make('Account')->width('20%'),
            Header::make('Start Date')->width('15%'),
            Header::make('End Date')->width('15%'),
        ];

        // Adjust the number of columns dynamically based on interval type
        switch ($intervalType) {
            case 'day':
                $headers[] = Header::make('Daily Budget')->width('20%')->align('right');

                break;
            case 'week':
                $headers[] = Header::make('Weekly Budget')->width('20%')->align('right');

                break;
            case 'month':
                $headers[] = Header::make('Monthly Budget')->width('20%')->align('right');

                break;
            case 'quarter':
                $headers[] = Header::make('Quarterly Budget')->width('20%')->align('right');

                break;
            case 'year':
                $headers[] = Header::make('Yearly Budget')->width('20%')->align('right');

                break;
        }

        return $headers;
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
