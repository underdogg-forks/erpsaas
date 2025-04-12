<?php

namespace App\Filament\Company\Resources\Common;

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\AdjustmentCategory;
use App\Enums\Accounting\AdjustmentType;
use App\Enums\Common\OfferingType;
use App\Filament\Company\Resources\Common\OfferingResource\Pages;
use App\Filament\Forms\Components\Banner;
use App\Filament\Forms\Components\CreateAccountSelect;
use App\Filament\Forms\Components\CreateAdjustmentSelect;
use App\Models\Common\Offering;
use App\Utilities\Currency\CurrencyAccessor;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use JaOcero\RadioDeck\Forms\Components\RadioDeck;

class OfferingResource extends Resource
{
    protected static ?string $model = Offering::class;

    protected static ?string $navigationIcon = 'heroicon-o-square-3-stack-3d';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Banner::make('inactiveAdjustments')
                    ->label('Inactive adjustments')
                    ->warning()
                    ->icon('heroicon-o-exclamation-triangle')
                    ->visible(fn (Offering $record) => $record->hasInactiveAdjustments())
                    ->columnSpanFull()
                    ->description(function (Offering $record) {
                        $inactiveAdjustments = collect();

                        foreach ($record->adjustments as $adjustment) {
                            if ($adjustment->isInactive() && $inactiveAdjustments->doesntContain($adjustment->name)) {
                                $inactiveAdjustments->push($adjustment->name);
                            }
                        }

                        $adjustmentsList = $inactiveAdjustments->map(static function ($name) {
                            return "<span class='font-medium'>{$name}</span>";
                        })->join(', ');

                        $output = "<p class='text-sm'>This offering contains inactive adjustments that need to be addressed: {$adjustmentsList}</p>";

                        return new HtmlString($output);
                    }),
                Forms\Components\Section::make('General')
                    ->schema([
                        RadioDeck::make('type')
                            ->options(OfferingType::class)
                            ->default(OfferingType::Product)
                            ->icons(OfferingType::class)
                            ->color('primary')
                            ->columns()
                            ->required(),
                        Forms\Components\TextInput::make('name')
                            ->autofocus()
                            ->required()
                            ->columnStart(1)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('price')
                            ->required()
                            ->money(),
                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->columnSpan(2)
                            ->rows(3),
                        Forms\Components\CheckboxList::make('attributes')
                            ->options([
                                'Sellable' => 'Sellable',
                                'Purchasable' => 'Purchasable',
                            ])
                            ->hiddenLabel()
                            ->required()
                            ->live()
                            ->bulkToggleable()
                            ->validationMessages([
                                'required' => 'The offering must be either sellable or purchasable.',
                            ]),
                    ])->columns(),
                // Sellable Section
                Forms\Components\Section::make('Sale Information')
                    ->schema([
                        CreateAccountSelect::make('income_account_id')
                            ->label('Income account')
                            ->category(AccountCategory::Revenue)
                            ->type(AccountType::OperatingRevenue)
                            ->required()
                            ->validationMessages([
                                'required' => 'The income account is required for sellable offerings.',
                            ]),
                        CreateAdjustmentSelect::make('salesTaxes')
                            ->label('Sales tax')
                            ->category(AdjustmentCategory::Tax)
                            ->type(AdjustmentType::Sales)
                            ->multiple(),
                        CreateAdjustmentSelect::make('salesDiscounts')
                            ->label('Sales discount')
                            ->category(AdjustmentCategory::Discount)
                            ->type(AdjustmentType::Sales)
                            ->multiple(),
                    ])
                    ->columns()
                    ->visible(static fn (Forms\Get $get) => in_array('Sellable', $get('attributes') ?? [])),

                // Purchasable Section
                Forms\Components\Section::make('Purchase Information')
                    ->schema([
                        CreateAccountSelect::make('expense_account_id')
                            ->label('Expense account')
                            ->category(AccountCategory::Expense)
                            ->type(AccountType::OperatingExpense)
                            ->required()
                            ->validationMessages([
                                'required' => 'The expense account is required for purchasable offerings.',
                            ]),
                        CreateAdjustmentSelect::make('purchaseTaxes')
                            ->label('Purchase tax')
                            ->category(AdjustmentCategory::Tax)
                            ->type(AdjustmentType::Purchase)
                            ->multiple(),
                        CreateAdjustmentSelect::make('purchaseDiscounts')
                            ->label('Purchase discount')
                            ->category(AdjustmentCategory::Discount)
                            ->type(AdjustmentType::Purchase)
                            ->multiple(),
                    ])
                    ->columns()
                    ->visible(static fn (Forms\Get $get) => in_array('Purchasable', $get('attributes') ?? [])),
            ])->columns();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $query->selectRaw("
                        *,
                        CONCAT_WS(' & ',
                            CASE WHEN sellable THEN 'Sellable' END,
                            CASE WHEN purchasable THEN 'Purchasable' END
                        ) AS attributes
                    ");
            })
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name'),
                Tables\Columns\TextColumn::make('attributes')
                    ->label('Attributes')
                    ->badge(),
                Tables\Columns\TextColumn::make('type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('price')
                    ->currency(CurrencyAccessor::getDefaultCurrency(), true)
                    ->sortable()
                    ->description(function (Offering $record) {
                        $adjustments = $record->adjustments()
                            ->pluck('name')
                            ->join(', ');

                        if (empty($adjustments)) {
                            return null;
                        }

                        $adjustmentsList = Str::of($adjustments)->limit(40);

                        return "+ {$adjustmentsList}";
                    }),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListOfferings::route('/'),
            'create' => Pages\CreateOffering::route('/create'),
            'edit' => Pages\EditOffering::route('/{record}/edit'),
        ];
    }
}
