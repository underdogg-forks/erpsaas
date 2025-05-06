<?php

namespace App\Filament\Company\Resources\Purchases;

use App\Enums\Accounting\AdjustmentCategory;
use App\Enums\Accounting\AdjustmentStatus;
use App\Enums\Accounting\AdjustmentType;
use App\Enums\Accounting\BillStatus;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\DocumentType;
use App\Enums\Accounting\PaymentMethod;
use App\Enums\Setting\PaymentTerms;
use App\Filament\Company\Resources\Purchases\BillResource\Pages;
use App\Filament\Company\Resources\Purchases\VendorResource\RelationManagers\BillsRelationManager;
use App\Filament\Forms\Components\CreateAdjustmentSelect;
use App\Filament\Forms\Components\CreateCurrencySelect;
use App\Filament\Forms\Components\CreateOfferingSelect;
use App\Filament\Forms\Components\CreateVendorSelect;
use App\Filament\Forms\Components\CustomTableRepeater;
use App\Filament\Forms\Components\DocumentTotals;
use App\Filament\Tables\Actions\ReplicateBulkAction;
use App\Filament\Tables\Columns;
use App\Filament\Tables\Filters\DateRangeFilter;
use App\Models\Accounting\Adjustment;
use App\Models\Accounting\Bill;
use App\Models\Accounting\DocumentLineItem;
use App\Models\Banking\BankAccount;
use App\Models\Common\Offering;
use App\Models\Common\Vendor;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use App\Utilities\RateCalculator;
use Awcodes\TableRepeater\Header;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Table;
use Guava\FilamentClusters\Forms\Cluster;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class BillResource extends Resource
{
    protected static ?string $model = Bill::class;

    public static function form(Form $form): Form
    {
        $company = Auth::user()->currentCompany;

        $settings = $company->defaultBill;

        return $form
            ->schema([
                Forms\Components\Section::make('Bill Details')
                    ->schema([
                        Forms\Components\Split::make([
                            Forms\Components\Group::make([
                                CreateVendorSelect::make('vendor_id')
                                    ->label('Vendor')
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                                        if (! $state) {
                                            return;
                                        }

                                        $currencyCode = Vendor::find($state)?->currency_code;

                                        if ($currencyCode) {
                                            $set('currency_code', $currencyCode);
                                        }
                                    }),
                                CreateCurrencySelect::make('currency_code'),
                            ]),
                            Forms\Components\Group::make([
                                Forms\Components\TextInput::make('bill_number')
                                    ->label('Bill number')
                                    ->default(static fn () => Bill::getNextDocumentNumber())
                                    ->required(),
                                Forms\Components\TextInput::make('order_number')
                                    ->label('P.O/S.O Number'),
                                Cluster::make([
                                    Forms\Components\DatePicker::make('date')
                                        ->label('Bill date')
                                        ->live()
                                        ->default(now())
                                        ->disabled(function (?Bill $record) {
                                            return $record?->hasPayments();
                                        })
                                        ->columnSpan(2)
                                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                                            $date = $state;
                                            $dueDate = $get('due_date');

                                            if ($date && $dueDate && $date > $dueDate) {
                                                $set('due_date', $date);
                                            }

                                            // Update due date based on payment terms if selected
                                            $paymentTerms = $get('payment_terms');
                                            if ($date && $paymentTerms && $paymentTerms !== 'custom') {
                                                $terms = PaymentTerms::parse($paymentTerms);
                                                $set('due_date', Carbon::parse($date)->addDays($terms->getDays())->toDateString());
                                            }
                                        }),
                                    Forms\Components\Select::make('payment_terms')
                                        ->label('Payment terms')
                                        ->options(function () {
                                            return collect(PaymentTerms::cases())
                                                ->mapWithKeys(function (PaymentTerms $paymentTerm) {
                                                    return [$paymentTerm->value => $paymentTerm->getLabel()];
                                                })
                                                ->put('custom', 'Custom')
                                                ->toArray();
                                        })
                                        ->selectablePlaceholder(false)
                                        ->default($settings->payment_terms->value)
                                        ->live()
                                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                                            if (! $state || $state === 'custom') {
                                                return;
                                            }

                                            $date = $get('date');
                                            if ($date) {
                                                $terms = PaymentTerms::parse($state);
                                                $set('due_date', Carbon::parse($date)->addDays($terms->getDays())->toDateString());
                                            }
                                        }),
                                ])
                                    ->label('Bill date')
                                    ->columns(3),
                                Forms\Components\DatePicker::make('due_date')
                                    ->label('Due date')
                                    ->default(function () use ($company) {
                                        return now()->addDays($company->defaultBill->payment_terms->getDays());
                                    })
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state) {
                                        if (! $state) {
                                            return;
                                        }

                                        $date = $get('date');
                                        $paymentTerms = $get('payment_terms');

                                        if (! $date || $paymentTerms === 'custom') {
                                            return;
                                        }

                                        $term = PaymentTerms::parse($paymentTerms);
                                        $expected = Carbon::parse($date)->addDays($term->getDays());

                                        if (! Carbon::parse($state)->isSameDay($expected)) {
                                            $set('payment_terms', 'custom');
                                        }
                                    }),
                                Forms\Components\Select::make('discount_method')
                                    ->label('Discount method')
                                    ->options(DocumentDiscountMethod::class)
                                    ->softRequired()
                                    ->default($settings->discount_method)
                                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                                        $discountMethod = DocumentDiscountMethod::parse($state);

                                        if ($discountMethod->isPerDocument()) {
                                            $set('lineItems.*.purchaseDiscounts', []);
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
                            ->orderColumn('line_number')
                            ->reorderAtStart()
                            ->cloneable()
                            ->addActionLabel('Add an item')
                            ->headers(function (Forms\Get $get) use ($settings) {
                                $hasDiscounts = DocumentDiscountMethod::parse($get('discount_method'))->isPerLineItem();

                                $headers = [
                                    Header::make($settings->resolveColumnLabel('item_name', 'Items'))
                                        ->width('30%'),
                                    Header::make($settings->resolveColumnLabel('unit_name', 'Quantity'))
                                        ->width('10%'),
                                    Header::make($settings->resolveColumnLabel('price_name', 'Price'))
                                        ->width('10%'),
                                ];

                                if ($hasDiscounts) {
                                    $headers[] = Header::make('Adjustments')->width('30%');
                                } else {
                                    $headers[] = Header::make('Taxes')->width('30%');
                                }

                                $headers[] = Header::make($settings->resolveColumnLabel('amount_name', 'Amount'))
                                    ->width('10%')
                                    ->align('right');

                                return $headers;
                            })
                            ->schema([
                                Forms\Components\Group::make([
                                    CreateOfferingSelect::make('offering_id')
                                        ->label('Item')
                                        ->hiddenLabel()
                                        ->placeholder('Select item')
                                        ->required()
                                        ->live()
                                        ->inlineSuffix()
                                        ->purchasable()
                                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, $state, ?DocumentLineItem $record) {
                                            $offeringId = $state;
                                            $discountMethod = DocumentDiscountMethod::parse($get('../../discount_method'));
                                            $isPerLineItem = $discountMethod->isPerLineItem();

                                            $existingTaxIds = [];
                                            $existingDiscountIds = [];

                                            if ($record) {
                                                $existingTaxIds = $record->purchaseTaxes()->pluck('adjustments.id')->toArray();
                                                if ($isPerLineItem) {
                                                    $existingDiscountIds = $record->purchaseDiscounts()->pluck('adjustments.id')->toArray();
                                                }
                                            }

                                            $with = [
                                                'purchaseTaxes' => static function ($query) use ($existingTaxIds) {
                                                    $query->where(static function ($query) use ($existingTaxIds) {
                                                        $query->where('status', AdjustmentStatus::Active)
                                                            ->orWhereIn('adjustments.id', $existingTaxIds);
                                                    });
                                                },
                                            ];

                                            if ($isPerLineItem) {
                                                $with['purchaseDiscounts'] = static function ($query) use ($existingDiscountIds) {
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
                                            $set('purchaseTaxes', $offeringRecord->purchaseTaxes->pluck('id')->toArray());

                                            if ($isPerLineItem) {
                                                $set('purchaseDiscounts', $offeringRecord->purchaseDiscounts->pluck('id')->toArray());
                                            }
                                        }),
                                    Forms\Components\TextInput::make('description')
                                        ->placeholder('Enter item description')
                                        ->hiddenLabel(),
                                ])->columnSpan(1),
                                Forms\Components\TextInput::make('quantity')
                                    ->required()
                                    ->numeric()
                                    ->live()
                                    ->maxValue(9999999999.99)
                                    ->default(1),
                                Forms\Components\TextInput::make('unit_price')
                                    ->label('Price')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->live()
                                    ->maxValue(9999999999.99)
                                    ->default(0),
                                Forms\Components\Group::make([
                                    CreateAdjustmentSelect::make('purchaseTaxes')
                                        ->label('Taxes')
                                        ->hiddenLabel()
                                        ->placeholder('Select taxes')
                                        ->category(AdjustmentCategory::Tax)
                                        ->type(AdjustmentType::Purchase)
                                        ->adjustmentsRelationship('purchaseTaxes')
                                        ->saveRelationshipsUsing(null)
                                        ->dehydrated(true)
                                        ->inlineSuffix()
                                        ->preload()
                                        ->multiple()
                                        ->live()
                                        ->searchable(),
                                    CreateAdjustmentSelect::make('purchaseDiscounts')
                                        ->label('Discounts')
                                        ->hiddenLabel()
                                        ->placeholder('Select discounts')
                                        ->category(AdjustmentCategory::Discount)
                                        ->type(AdjustmentType::Purchase)
                                        ->adjustmentsRelationship('purchaseDiscounts')
                                        ->saveRelationshipsUsing(null)
                                        ->dehydrated(true)
                                        ->inlineSuffix()
                                        ->multiple()
                                        ->live()
                                        ->hidden(function (Forms\Get $get) {
                                            $discountMethod = DocumentDiscountMethod::parse($get('../../discount_method'));

                                            return $discountMethod->isPerDocument();
                                        })
                                        ->searchable(),
                                ])->columnSpan(1),
                                Forms\Components\Placeholder::make('total')
                                    ->hiddenLabel()
                                    ->extraAttributes(['class' => 'text-left sm:text-right'])
                                    ->content(function (Forms\Get $get) {
                                        $quantity = max((float) ($get('quantity') ?? 0), 0);
                                        $unitPrice = max((float) ($get('unit_price') ?? 0), 0);
                                        $purchaseTaxes = $get('purchaseTaxes') ?? [];
                                        $purchaseDiscounts = $get('purchaseDiscounts') ?? [];
                                        $currencyCode = $get('../../currency_code') ?? CurrencyAccessor::getDefaultCurrency();

                                        $subtotal = $quantity * $unitPrice;

                                        $subtotalInCents = CurrencyConverter::convertToCents($subtotal, $currencyCode);

                                        $taxAmountInCents = Adjustment::whereIn('id', $purchaseTaxes)
                                            ->get()
                                            ->sum(function (Adjustment $adjustment) use ($subtotalInCents) {
                                                if ($adjustment->computation->isPercentage()) {
                                                    return RateCalculator::calculatePercentage($subtotalInCents, $adjustment->getRawOriginal('rate'));
                                                } else {
                                                    return $adjustment->getRawOriginal('rate');
                                                }
                                            });

                                        $discountAmountInCents = Adjustment::whereIn('id', $purchaseDiscounts)
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
                            ->type(DocumentType::Bill),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('due_date')
            ->columns([
                Columns::id(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Due')
                    ->asRelativeDay()
                    ->sortable(),
                Tables\Columns\TextColumn::make('date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('bill_number')
                    ->label('Number')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vendor.name')
                    ->sortable()
                    ->searchable()
                    ->hiddenOn(BillsRelationManager::class),
                Tables\Columns\TextColumn::make('total')
                    ->currencyWithConversion(static fn (Bill $record) => $record->currency_code)
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('amount_paid')
                    ->label('Amount paid')
                    ->currencyWithConversion(static fn (Bill $record) => $record->currency_code)
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('amount_due')
                    ->label('Amount due')
                    ->currencyWithConversion(static fn (Bill $record) => $record->currency_code)
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('vendor')
                    ->relationship('vendor', 'name')
                    ->searchable()
                    ->preload()
                    ->hiddenOn(BillsRelationManager::class),
                Tables\Filters\SelectFilter::make('status')
                    ->options(BillStatus::class)
                    ->native(false),
                Tables\Filters\TernaryFilter::make('has_payments')
                    ->label('Has payments')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('payments'),
                        false: fn (Builder $query) => $query->whereDoesntHave('payments'),
                    ),
                DateRangeFilter::make('date')
                    ->fromLabel('From date')
                    ->untilLabel('To date')
                    ->indicatorLabel('Date'),
                DateRangeFilter::make('due_date')
                    ->fromLabel('From due date')
                    ->untilLabel('To due date')
                    ->indicatorLabel('Due'),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ActionGroup::make([
                        Tables\Actions\EditAction::make()
                            ->url(static fn (Bill $record) => Pages\EditBill::getUrl(['record' => $record])),
                        Tables\Actions\ViewAction::make()
                            ->url(static fn (Bill $record) => Pages\ViewBill::getUrl(['record' => $record])),
                        Bill::getReplicateAction(Tables\Actions\ReplicateAction::class),
                        Tables\Actions\Action::make('recordPayment')
                            ->label('Record payment')
                            ->stickyModalHeader()
                            ->stickyModalFooter()
                            ->modalFooterActionsAlignment(Alignment::End)
                            ->modalWidth(MaxWidth::TwoExtraLarge)
                            ->icon('heroicon-o-credit-card')
                            ->visible(function (Bill $record) {
                                return $record->canRecordPayment();
                            })
                            ->mountUsing(function (Bill $record, Form $form) {
                                $form->fill([
                                    'posted_at' => now(),
                                    'amount' => $record->amount_due,
                                ]);
                            })
                            ->databaseTransaction()
                            ->successNotificationTitle('Payment recorded')
                            ->form([
                                Forms\Components\DatePicker::make('posted_at')
                                    ->label('Date'),
                                Forms\Components\TextInput::make('amount')
                                    ->label('Amount')
                                    ->required()
                                    ->money(fn (Bill $record) => $record->currency_code)
                                    ->live(onBlur: true)
                                    ->helperText(function (Bill $record, $state) {
                                        $billCurrency = $record->currency_code;
                                        if (! CurrencyConverter::isValidAmount($state, $billCurrency)) {
                                            return null;
                                        }

                                        $amountDue = $record->getRawOriginal('amount_due');
                                        $amount = CurrencyConverter::convertToCents($state, $billCurrency);

                                        if ($amount <= 0) {
                                            return 'Please enter a valid positive amount';
                                        }

                                        $newAmountDue = $amountDue - $amount;

                                        return match (true) {
                                            $newAmountDue > 0 => 'Amount due after payment will be ' . CurrencyConverter::formatCentsToMoney($newAmountDue, $billCurrency),
                                            $newAmountDue === 0 => 'Bill will be fully paid',
                                            default => 'Amount exceeds bill total by ' . CurrencyConverter::formatCentsToMoney(abs($newAmountDue), $billCurrency),
                                        };
                                    })
                                    ->rules([
                                        static fn (Bill $record): Closure => static function (string $attribute, $value, Closure $fail) use ($record) {
                                            if (! CurrencyConverter::isValidAmount($value, $record->currency_code)) {
                                                $fail('Please enter a valid amount');
                                            }
                                        },
                                    ]),
                                Forms\Components\Select::make('payment_method')
                                    ->label('Payment method')
                                    ->required()
                                    ->options(PaymentMethod::class),
                                Forms\Components\Select::make('bank_account_id')
                                    ->label('Account')
                                    ->required()
                                    ->options(function () {
                                        return BankAccount::query()
                                            ->join('accounts', 'bank_accounts.account_id', '=', 'accounts.id')
                                            ->select(['bank_accounts.id', 'accounts.name'])
                                            ->pluck('accounts.name', 'bank_accounts.id')
                                            ->toArray();
                                    })
                                    ->searchable(),
                                Forms\Components\Textarea::make('notes')
                                    ->label('Notes'),
                            ])
                            ->action(function (Bill $record, Tables\Actions\Action $action, array $data) {
                                $record->recordPayment($data);

                                $action->success();
                            }),
                    ])->dropdown(false),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    ReplicateBulkAction::make()
                        ->label('Replicate')
                        ->modalWidth(MaxWidth::Large)
                        ->modalDescription('Replicating bills will also replicate their line items. Are you sure you want to proceed?')
                        ->successNotificationTitle('Bills replicated successfully')
                        ->failureNotificationTitle('Failed to replicate bills')
                        ->databaseTransaction()
                        ->deselectRecordsAfterCompletion()
                        ->excludeAttributes([
                            'status',
                            'amount_paid',
                            'amount_due',
                            'created_by',
                            'updated_by',
                            'created_at',
                            'updated_at',
                            'bill_number',
                            'date',
                            'due_date',
                            'paid_at',
                        ])
                        ->beforeReplicaSaved(function (Bill $replica) {
                            $replica->status = BillStatus::Open;
                            $replica->bill_number = Bill::getNextDocumentNumber();
                            $replica->date = now();
                            $replica->due_date = now()->addDays($replica->company->defaultBill->payment_terms->getDays());
                        })
                        ->withReplicatedRelationships(['lineItems'])
                        ->withExcludedRelationshipAttributes('lineItems', [
                            'subtotal',
                            'total',
                            'created_by',
                            'updated_by',
                            'created_at',
                            'updated_at',
                        ]),
                    Tables\Actions\BulkAction::make('recordPayments')
                        ->label('Record payments')
                        ->icon('heroicon-o-credit-card')
                        ->stickyModalHeader()
                        ->stickyModalFooter()
                        ->modalFooterActionsAlignment(Alignment::End)
                        ->modalWidth(MaxWidth::TwoExtraLarge)
                        ->databaseTransaction()
                        ->successNotificationTitle('Payments recorded')
                        ->failureNotificationTitle('Failed to record payments')
                        ->deselectRecordsAfterCompletion()
                        ->beforeFormFilled(function (Collection $records, Tables\Actions\BulkAction $action) {
                            $isInvalid = $records->contains(fn (Bill $bill) => ! $bill->canRecordPayment());

                            if ($isInvalid) {
                                Notification::make()
                                    ->title('Payment recording failed')
                                    ->body('Bills that are either paid, voided, or are in a foreign currency cannot be processed through bulk payments. Please adjust your selection and try again.')
                                    ->persistent()
                                    ->danger()
                                    ->send();

                                $action->cancel(true);
                            }
                        })
                        ->mountUsing(function (Collection $records, Form $form) {
                            $totalAmountDue = $records->sum(fn (Bill $bill) => $bill->getRawOriginal('amount_due'));

                            $form->fill([
                                'posted_at' => now(),
                                'amount' => CurrencyConverter::convertCentsToFormatSimple($totalAmountDue),
                            ]);
                        })
                        ->form([
                            Forms\Components\DatePicker::make('posted_at')
                                ->label('Date'),
                            Forms\Components\TextInput::make('amount')
                                ->label('Amount')
                                ->required()
                                ->money()
                                ->rules([
                                    static fn (): Closure => static function (string $attribute, $value, Closure $fail) {
                                        if (! CurrencyConverter::isValidAmount($value)) {
                                            $fail('Please enter a valid amount');
                                        }
                                    },
                                ]),
                            Forms\Components\Select::make('payment_method')
                                ->label('Payment method')
                                ->required()
                                ->options(PaymentMethod::class),
                            Forms\Components\Select::make('bank_account_id')
                                ->label('Account')
                                ->required()
                                ->options(function () {
                                    return BankAccount::query()
                                        ->join('accounts', 'bank_accounts.account_id', '=', 'accounts.id')
                                        ->select(['bank_accounts.id', 'accounts.name'])
                                        ->pluck('accounts.name', 'bank_accounts.id')
                                        ->toArray();
                                })
                                ->searchable(),
                            Forms\Components\Textarea::make('notes')
                                ->label('Notes'),
                        ])
                        ->before(function (Collection $records, Tables\Actions\BulkAction $action, array $data) {
                            $totalPaymentAmount = CurrencyConverter::convertToCents($data['amount']);
                            $totalAmountDue = $records->sum(fn (Bill $bill) => $bill->getRawOriginal('amount_due'));

                            if ($totalPaymentAmount > $totalAmountDue) {
                                $formattedTotalAmountDue = CurrencyConverter::formatCentsToMoney($totalAmountDue);

                                Notification::make()
                                    ->title('Excess payment amount')
                                    ->body("The payment amount exceeds the total amount due of {$formattedTotalAmountDue}. Please adjust the payment amount and try again.")
                                    ->persistent()
                                    ->warning()
                                    ->send();

                                $action->halt(true);
                            }
                        })
                        ->action(function (Collection $records, Tables\Actions\BulkAction $action, array $data) {
                            $totalPaymentAmount = CurrencyConverter::convertToCents($data['amount']);
                            $remainingAmount = $totalPaymentAmount;

                            $records->each(function (Bill $record) use (&$remainingAmount, $data) {
                                $amountDue = $record->getRawOriginal('amount_due');

                                if ($amountDue <= 0 || $remainingAmount <= 0) {
                                    return;
                                }

                                $paymentAmount = min($amountDue, $remainingAmount);
                                $data['amount'] = CurrencyConverter::convertCentsToFormatSimple($paymentAmount);

                                $record->recordPayment($data);
                                $remainingAmount -= $paymentAmount;
                            });

                            $action->success();
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBills::route('/'),
            'create' => Pages\CreateBill::route('/create'),
            'view' => Pages\ViewBill::route('/{record}'),
            'edit' => Pages\EditBill::route('/{record}/edit'),
        ];
    }

    public static function getWidgets(): array
    {
        return [
            BillResource\Widgets\BillOverview::class,
        ];
    }
}
