<?php

namespace App\Filament\Company\Resources\Sales;

use App\Enums\Accounting\AdjustmentCategory;
use App\Enums\Accounting\AdjustmentStatus;
use App\Enums\Accounting\AdjustmentType;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\DocumentType;
use App\Enums\Accounting\RecurringInvoiceStatus;
use App\Enums\Setting\PaymentTerms;
use App\Filament\Company\Resources\Sales\ClientResource\RelationManagers\RecurringInvoicesRelationManager;
use App\Filament\Company\Resources\Sales\RecurringInvoiceResource\Pages;
use App\Filament\Forms\Components\CreateAdjustmentSelect;
use App\Filament\Forms\Components\CreateClientSelect;
use App\Filament\Forms\Components\CreateCurrencySelect;
use App\Filament\Forms\Components\CreateOfferingSelect;
use App\Filament\Forms\Components\CustomTableRepeater;
use App\Filament\Forms\Components\DocumentFooterSection;
use App\Filament\Forms\Components\DocumentHeaderSection;
use App\Filament\Forms\Components\DocumentTotals;
use App\Filament\Tables\Columns;
use App\Models\Accounting\Adjustment;
use App\Models\Accounting\DocumentLineItem;
use App\Models\Accounting\RecurringInvoice;
use App\Models\Common\Client;
use App\Models\Common\Offering;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use App\Utilities\RateCalculator;
use Awcodes\TableRepeater\Header;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class RecurringInvoiceResource extends Resource
{
    protected static ?string $model = RecurringInvoice::class;

    public static function form(Form $form): Form
    {
        $company = Auth::user()->currentCompany;

        $settings = $company->defaultInvoice;

        return $form
            ->schema([
                DocumentHeaderSection::make('Invoice Header')
                    ->defaultHeader($settings->header)
                    ->defaultSubheader($settings->subheader),
                Forms\Components\Section::make('Invoice Details')
                    ->schema([
                        Forms\Components\Split::make([
                            Forms\Components\Group::make([
                                CreateClientSelect::make('client_id')
                                    ->label('Client')
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                                        if (! $state) {
                                            return;
                                        }

                                        $currencyCode = Client::find($state)?->currency_code;

                                        if ($currencyCode) {
                                            $set('currency_code', $currencyCode);
                                        }
                                    }),
                                CreateCurrencySelect::make('currency_code'),
                            ]),
                            Forms\Components\Group::make([
                                Forms\Components\Placeholder::make('invoice_number')
                                    ->label('Invoice number')
                                    ->content('Auto-generated'),
                                Forms\Components\TextInput::make('order_number')
                                    ->label('P.O/S.O Number'),
                                Forms\Components\Placeholder::make('date')
                                    ->label('Invoice date')
                                    ->content('Auto-generated'),
                                Forms\Components\Select::make('payment_terms')
                                    ->label('Payment due')
                                    ->options(PaymentTerms::class)
                                    ->softRequired()
                                    ->default($settings->payment_terms)
                                    ->live(),
                                Forms\Components\Select::make('discount_method')
                                    ->label('Discount method')
                                    ->options(DocumentDiscountMethod::class)
                                    ->selectablePlaceholder(false)
                                    ->default($settings->discount_method)
                                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                                        $discountMethod = DocumentDiscountMethod::parse($state);

                                        if ($discountMethod->isPerDocument()) {
                                            $set('lineItems.*.salesDiscounts', []);
                                        }
                                    })
                                    ->live(),
                            ])->grow(true),
                        ])->from('md'),
                        CustomTableRepeater::make('lineItems')
                            ->hiddenLabel()
                            ->relationship()
                            ->saveRelationshipsUsing(null)
                            ->dehydrated(true)
                            ->reorderable()
                            ->reorderAtStart()
                            ->cloneable()
                            ->headers(function (Forms\Get $get) use ($settings) {
                                $hasDiscounts = DocumentDiscountMethod::parse($get('discount_method'))->isPerLineItem();

                                $headers = [
                                    Header::make($settings->resolveColumnLabel('item_name', 'Items'))
                                        ->width($hasDiscounts ? '15%' : '20%'),
                                    Header::make('Description')
                                        ->width($hasDiscounts ? '15%' : '20%'),
                                    Header::make($settings->resolveColumnLabel('unit_name', 'Quantity'))
                                        ->width('10%'),
                                    Header::make($settings->resolveColumnLabel('price_name', 'Price'))
                                        ->width('10%'),
                                    Header::make('Taxes')
                                        ->width($hasDiscounts ? '20%' : '30%'),
                                ];

                                if ($hasDiscounts) {
                                    $headers[] = Header::make('Discounts')->width('20%');
                                }

                                $headers[] = Header::make($settings->resolveColumnLabel('amount_name', 'Amount'))
                                    ->width('10%')
                                    ->align('right');

                                return $headers;
                            })
                            ->schema([
                                CreateOfferingSelect::make('offering_id')
                                    ->label('Item')
                                    ->required()
                                    ->live()
                                    ->sellable()
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state, ?DocumentLineItem $record) {
                                        $offeringId = $state;
                                        $discountMethod = DocumentDiscountMethod::parse($get('../../discount_method'));
                                        $isPerLineItem = $discountMethod->isPerLineItem();

                                        $existingTaxIds = [];
                                        $existingDiscountIds = [];

                                        if ($record) {
                                            $existingTaxIds = $record->salesTaxes()->pluck('adjustments.id')->toArray();
                                            if ($isPerLineItem) {
                                                $existingDiscountIds = $record->salesDiscounts()->pluck('adjustments.id')->toArray();
                                            }
                                        }

                                        $with = [
                                            'salesTaxes' => static function ($query) use ($existingTaxIds) {
                                                $query->where(static function ($query) use ($existingTaxIds) {
                                                    $query->where('status', AdjustmentStatus::Active)
                                                        ->orWhereIn('adjustments.id', $existingTaxIds);
                                                });
                                            },
                                        ];

                                        if ($isPerLineItem) {
                                            $with['salesDiscounts'] = static function ($query) use ($existingDiscountIds) {
                                                $query->where(static function ($query) use ($existingDiscountIds) {
                                                    $query->where('status', AdjustmentStatus::Active)
                                                        ->orWhereIn('adjustments.id', $existingDiscountIds);
                                                });
                                            };
                                        }

                                        $offeringRecord = Offering::with($with)->find($offeringId);

                                        if (! $offeringRecord) {
                                            return;
                                        }

                                        $unitPrice = CurrencyConverter::convertToFloat($offeringRecord->price, $get('../../currency_code') ?? CurrencyAccessor::getDefaultCurrency());

                                        $set('description', $offeringRecord->description);
                                        $set('unit_price', $unitPrice);
                                        $set('salesTaxes', $offeringRecord->salesTaxes->pluck('id')->toArray());

                                        if ($isPerLineItem) {
                                            $set('salesDiscounts', $offeringRecord->salesDiscounts->pluck('id')->toArray());
                                        }
                                    }),
                                Forms\Components\TextInput::make('description'),
                                Forms\Components\TextInput::make('quantity')
                                    ->required()
                                    ->numeric()
                                    ->live()
                                    ->maxValue(9999999999.99)
                                    ->default(1),
                                Forms\Components\TextInput::make('unit_price')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->live()
                                    ->maxValue(9999999999.99)
                                    ->default(0),
                                CreateAdjustmentSelect::make('salesTaxes')
                                    ->label('Taxes')
                                    ->category(AdjustmentCategory::Tax)
                                    ->type(AdjustmentType::Sales)
                                    ->adjustmentsRelationship('salesTaxes')
                                    ->saveRelationshipsUsing(null)
                                    ->dehydrated(true)
                                    ->preload()
                                    ->multiple()
                                    ->live()
                                    ->searchable(),
                                CreateAdjustmentSelect::make('salesDiscounts')
                                    ->label('Discounts')
                                    ->category(AdjustmentCategory::Discount)
                                    ->type(AdjustmentType::Sales)
                                    ->adjustmentsRelationship('salesDiscounts')
                                    ->saveRelationshipsUsing(null)
                                    ->dehydrated(true)
                                    ->multiple()
                                    ->live()
                                    ->hidden(function (Forms\Get $get) {
                                        $discountMethod = DocumentDiscountMethod::parse($get('../../discount_method'));

                                        return $discountMethod->isPerDocument();
                                    })
                                    ->searchable(),
                                Forms\Components\Placeholder::make('total')
                                    ->hiddenLabel()
                                    ->extraAttributes(['class' => 'text-left sm:text-right'])
                                    ->content(function (Forms\Get $get) {
                                        $quantity = max((float) ($get('quantity') ?? 0), 0);
                                        $unitPrice = max((float) ($get('unit_price') ?? 0), 0);
                                        $salesTaxes = $get('salesTaxes') ?? [];
                                        $salesDiscounts = $get('salesDiscounts') ?? [];
                                        $currencyCode = $get('../../currency_code') ?? CurrencyAccessor::getDefaultCurrency();

                                        $subtotal = $quantity * $unitPrice;

                                        $subtotalInCents = CurrencyConverter::convertToCents($subtotal, $currencyCode);

                                        $taxAmountInCents = Adjustment::whereIn('id', $salesTaxes)
                                            ->get()
                                            ->sum(function (Adjustment $adjustment) use ($subtotalInCents) {
                                                if ($adjustment->computation->isPercentage()) {
                                                    return RateCalculator::calculatePercentage($subtotalInCents, $adjustment->getRawOriginal('rate'));
                                                } else {
                                                    return $adjustment->getRawOriginal('rate');
                                                }
                                            });

                                        $discountAmountInCents = Adjustment::whereIn('id', $salesDiscounts)
                                            ->get()
                                            ->sum(function (Adjustment $adjustment) use ($subtotalInCents) {
                                                if ($adjustment->computation->isPercentage()) {
                                                    return RateCalculator::calculatePercentage($subtotalInCents, $adjustment->getRawOriginal('rate'));
                                                } else {
                                                    return $adjustment->getRawOriginal('rate');
                                                }
                                            });

                                        // Final total
                                        $totalInCents = $subtotalInCents + ($taxAmountInCents - $discountAmountInCents);

                                        return CurrencyConverter::formatCentsToMoney($totalInCents, $currencyCode);
                                    }),
                            ]),
                        DocumentTotals::make()
                            ->type(DocumentType::Invoice),
                        Forms\Components\Textarea::make('terms')
                            ->default($settings->terms)
                            ->columnSpanFull(),
                    ]),
                DocumentFooterSection::make('Invoice Footer')
                    ->defaultFooter($settings->footer),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('next_date')
            ->columns([
                Columns::id(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                Tables\Columns\TextColumn::make('client.name')
                    ->sortable()
                    ->searchable()
                    ->hiddenOn(RecurringInvoicesRelationManager::class),
                Tables\Columns\TextColumn::make('schedule')
                    ->label('Schedule')
                    ->getStateUsing(function (RecurringInvoice $record) {
                        return $record->getScheduleDescription();
                    })
                    ->description(function (RecurringInvoice $record) {
                        return $record->getTimelineDescription();
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->date()
                    ->sortable()
                    ->showOnTabs(['draft']),
                Tables\Columns\TextColumn::make('start_date')
                    ->label('First invoice')
                    ->date()
                    ->sortable()
                    ->showOnTabs(['draft']),
                Tables\Columns\TextColumn::make('last_date')
                    ->label('Last invoice')
                    ->date()
                    ->sortable()
                    ->hideOnTabs(['draft']),
                Tables\Columns\TextColumn::make('next_date')
                    ->label('Next invoice')
                    ->date()
                    ->sortable()
                    ->hideOnTabs(['draft']),
                Tables\Columns\TextColumn::make('total')
                    ->currencyWithConversion(static fn (RecurringInvoice $record) => $record->currency_code)
                    ->sortable()
                    ->toggleable()
                    ->alignEnd(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('client')
                    ->relationship('client', 'name')
                    ->searchable()
                    ->preload()
                    ->hiddenOn(RecurringInvoicesRelationManager::class),
                Tables\Filters\SelectFilter::make('status')
                    ->options(RecurringInvoiceStatus::class)
                    ->native(false),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ActionGroup::make([
                        Tables\Actions\EditAction::make()
                            ->url(static fn (RecurringInvoice $record): string => Pages\EditRecurringInvoice::getUrl(['record' => $record])),
                        Tables\Actions\ViewAction::make()
                            ->url(static fn (RecurringInvoice $record): string => Pages\ViewRecurringInvoice::getUrl(['record' => $record])),
                        RecurringInvoice::getManageScheduleAction(Tables\Actions\Action::class),
                    ])->dropdown(false),
                    Tables\Actions\DeleteAction::make(),
                ]),
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
            'index' => Pages\ListRecurringInvoices::route('/'),
            'create' => Pages\CreateRecurringInvoice::route('/create'),
            'view' => Pages\ViewRecurringInvoice::route('/{record}'),
            'edit' => Pages\EditRecurringInvoice::route('/{record}/edit'),
        ];
    }
}
