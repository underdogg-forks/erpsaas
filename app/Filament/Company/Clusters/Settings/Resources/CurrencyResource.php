<?php

namespace App\Filament\Company\Clusters\Settings\Resources;

use App\Facades\Forex;
use App\Filament\Company\Clusters\Settings;
use App\Filament\Company\Clusters\Settings\Resources\CurrencyResource\Pages;
use App\Models\Setting\Currency as CurrencyModel;
use App\Utilities\Currency\CurrencyAccessor;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;

class CurrencyResource extends Resource
{
    protected static ?string $model = CurrencyModel::class;

    protected static ?string $modelLabel = 'currency';

    protected static ?string $cluster = Settings::class;

    public static function getModelLabel(): string
    {
        $modelLabel = static::$modelLabel;

        return translate($modelLabel);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('General')
                    ->schema([
                        Forms\Components\Select::make('code')
                            ->options(CurrencyAccessor::getAvailableCurrencies())
                            ->searchable()
                            ->live()
                            ->required()
                            ->localizeLabel()
                            ->disabledOn('edit')
                            ->afterStateUpdated(static function (Forms\Set $set, $state) {
                                if (! $state) {
                                    return;
                                }

                                $defaultCurrencyCode = CurrencyAccessor::getDefaultCurrency();
                                $exchangeRate = Forex::getCachedExchangeRate($defaultCurrencyCode, $state);

                                if ($exchangeRate !== null) {
                                    $set('rate', $exchangeRate);
                                }
                            }),
                        Forms\Components\TextInput::make('rate')
                            ->numeric()
                            ->rule('gt:0')
                            ->live()
                            ->localizeLabel()
                            ->required(),
                    ])->columns(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->localizeLabel()
                    ->weight(FontWeight::Medium)
                    ->icon(static fn (CurrencyModel $record) => $record->isEnabled() ? 'heroicon-o-lock-closed' : null)
                    ->tooltip(static function (CurrencyModel $record) {
                        $tooltipMessage = translate('Default :record', [
                            'record' => static::getModelLabel(),
                        ]);

                        return $record->isEnabled() ? $tooltipMessage : null;
                    })
                    ->iconPosition('after')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('code')
                    ->localizeLabel()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('symbol')
                    ->localizeLabel()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rate')
                    ->localizeLabel()
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCurrencies::route('/'),
            'create' => Pages\CreateCurrency::route('/create'),
            'edit' => Pages\EditCurrency::route('/{record}/edit'),
        ];
    }
}
