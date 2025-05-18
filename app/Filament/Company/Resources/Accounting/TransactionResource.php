<?php

namespace App\Filament\Company\Resources\Accounting;

use App\Enums\Accounting\TransactionType;
use App\Filament\Company\Resources\Accounting\TransactionResource\Pages;
use App\Filament\Forms\Components\DateRangeSelect;
use App\Filament\Tables\Actions\EditTransactionAction;
use App\Filament\Tables\Actions\ReplicateBulkAction;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Transaction;
use App\Models\Common\Client;
use App\Models\Common\Vendor;
use Exception;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $recordTitleAttribute = 'description';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $query->with([
                    'account',
                    'bankAccount.account',
                    'journalEntries.account',
                    'payeeable',
                ])
                    ->where(function (Builder $query) {
                        $query->whereNull('transactionable_id')
                            ->orWhere('is_payment', true);
                    });
            })
            ->columns([
                Tables\Columns\TextColumn::make('posted_at')
                    ->label('Date')
                    ->sortable()
                    ->defaultDateFormat(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('payeeable.name')
                    ->label('Payee')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('bankAccount.account.name')
                    ->label('Account')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('account.name')
                    ->label('Category')
                    ->prefix(static fn (Transaction $transaction) => $transaction->type->isTransfer() ? 'Transfer to ' : null)
                    ->searchable()
                    ->toggleable()
                    ->state(static fn (Transaction $transaction) => $transaction->account->name ?? 'Journal Entry'),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->weight(static fn (Transaction $transaction) => $transaction->reviewed ? null : FontWeight::SemiBold)
                    ->color(
                        static fn (Transaction $transaction) => match ($transaction->type) {
                            TransactionType::Deposit => Color::rgb('rgb(' . Color::Green[700] . ')'),
                            TransactionType::Journal => 'primary',
                            default => null,
                        }
                    )
                    ->sortable()
                    ->currency(static fn (Transaction $transaction) => $transaction->bankAccount?->account->currency_code),
            ])
            ->defaultSort('posted_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('bank_account_id')
                    ->label('Account')
                    ->searchable()
                    ->options(static fn () => Transaction::getBankAccountOptions(false)),
                Tables\Filters\SelectFilter::make('account_id')
                    ->label('Category')
                    ->multiple()
                    ->options(static fn () => Transaction::getChartAccountOptions()),
                Tables\Filters\TernaryFilter::make('reviewed')
                    ->label('Status')
                    ->trueLabel('Reviewed')
                    ->falseLabel('Not Reviewed'),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->options(TransactionType::class),
                Tables\Filters\TernaryFilter::make('is_payment')
                    ->label('Payment')
                    ->default(false),
                Tables\Filters\SelectFilter::make('payee')
                    ->label('Payee')
                    ->options(static fn () => Transaction::getPayeeOptions())
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        $id = (int) $data['value'];

                        if ($id < 0) {
                            return $query->where('payeeable_type', Vendor::class)
                                ->where('payeeable_id', abs($id));
                        } else {
                            return $query->where('payeeable_type', Client::class)
                                ->where('payeeable_id', $id);
                        }
                    }),
                static::buildDateRangeFilter('posted_at', 'Posted', true),
                static::buildDateRangeFilter('updated_at', 'Last modified'),
            ])
            ->filtersFormSchema(fn (array $filters): array => [
                Grid::make()
                    ->schema([
                        $filters['bank_account_id'],
                        $filters['account_id'],
                        $filters['reviewed'],
                        $filters['type'],
                        $filters['is_payment'],
                        $filters['payee'],
                    ])
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'border-b border-gray-200 dark:border-white/10 pb-8']),
                $filters['posted_at'],
                $filters['updated_at'],
            ])
            ->filtersFormWidth(MaxWidth::ThreeExtraLarge)
            ->actions([
                Tables\Actions\Action::make('markAsReviewed')
                    ->label('Mark as reviewed')
                    ->view('filament.company.components.tables.actions.mark-as-reviewed')
                    ->icon(static fn (Transaction $transaction) => $transaction->reviewed ? 'heroicon-s-check-circle' : 'heroicon-o-check-circle')
                    ->color(static fn (Transaction $transaction, Tables\Actions\Action $action) => match (static::determineTransactionState($transaction, $action)) {
                        'reviewed' => 'primary',
                        'unreviewed' => Color::rgb('rgb(' . Color::Gray[600] . ')'),
                        'uncategorized' => 'gray',
                    })
                    ->tooltip(static fn (Transaction $transaction, Tables\Actions\Action $action) => match (static::determineTransactionState($transaction, $action)) {
                        'reviewed' => 'Reviewed',
                        'unreviewed' => 'Mark as reviewed',
                        'uncategorized' => 'Categorize first to mark as reviewed',
                    })
                    ->disabled(fn (Transaction $transaction): bool => $transaction->isUncategorized())
                    ->action(fn (Transaction $transaction) => $transaction->update(['reviewed' => ! $transaction->reviewed])),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ActionGroup::make([
                        EditTransactionAction::make('editTransaction')
                            ->visible(static fn (Transaction $transaction) => $transaction->type->isStandard() && ! $transaction->transactionable_id),
                        EditTransactionAction::make('editTransfer')
                            ->type(TransactionType::Transfer)
                            ->visible(static fn (Transaction $transaction) => $transaction->type->isTransfer()),
                        EditTransactionAction::make('editJournalEntry')
                            ->type(TransactionType::Journal)
                            ->visible(static fn (Transaction $transaction) => $transaction->type->isJournal() && ! $transaction->transactionable_id),
                        Tables\Actions\ReplicateAction::make()
                            ->excludeAttributes(['created_by', 'updated_by', 'created_at', 'updated_at'])
                            ->modal(false)
                            ->beforeReplicaSaved(static function (Transaction $replica) {
                                $replica->description = '(Copy of) ' . $replica->description;
                            })
                            ->hidden(static fn (Transaction $transaction) => $transaction->transactionable_id)
                            ->after(static function (Transaction $original, Transaction $replica) {
                                $original->journalEntries->each(function (JournalEntry $entry) use ($replica) {
                                    $entry->replicate([
                                        'transaction_id',
                                    ])->fill([
                                        'transaction_id' => $replica->id,
                                    ])->save();
                                });
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
                        ->modalDescription('Replicating transactions will also replicate their journal entries. Are you sure you want to proceed?')
                        ->successNotificationTitle('Transactions replicated successfully')
                        ->failureNotificationTitle('Failed to replicate transactions')
                        ->deselectRecordsAfterCompletion()
                        ->excludeAttributes(['created_by', 'updated_by', 'created_at', 'updated_at'])
                        ->beforeReplicaSaved(static function (Transaction $replica) {
                            $replica->description = '(Copy of) ' . $replica->description;
                        })
                        ->before(function (Collection $records, ReplicateBulkAction $action) {
                            $isInvalid = $records->contains(fn (Transaction $record) => $record->transactionable_id);

                            if ($isInvalid) {
                                Notification::make()
                                    ->title('Cannot replicate transactions')
                                    ->body('You cannot replicate transactions associated with bills or invoices')
                                    ->persistent()
                                    ->danger()
                                    ->send();

                                $action->cancel(true);
                            }
                        })
                        ->withReplicatedRelationships(['journalEntries']),
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
            'index' => Pages\ListTransactions::route('/'),
            'view' => Pages\ViewTransaction::route('/{record}'),
        ];
    }

    /**
     * @throws Exception
     */
    public static function buildDateRangeFilter(string $fieldPrefix, string $label, bool $hasBottomBorder = false): Tables\Filters\Filter
    {
        return Tables\Filters\Filter::make($fieldPrefix)
            ->columnSpanFull()
            ->form([
                Grid::make()
                    ->live()
                    ->schema([
                        DateRangeSelect::make("{$fieldPrefix}_date_range")
                            ->label($label)
                            ->selectablePlaceholder(false)
                            ->placeholder('Select a date range')
                            ->startDateField("{$fieldPrefix}_start_date")
                            ->endDateField("{$fieldPrefix}_end_date"),
                        DatePicker::make("{$fieldPrefix}_start_date")
                            ->label("{$label} from")
                            ->columnStart(1)
                            ->afterStateUpdated(static function (Set $set) use ($fieldPrefix) {
                                $set("{$fieldPrefix}_date_range", 'Custom');
                            }),
                        DatePicker::make("{$fieldPrefix}_end_date")
                            ->label("{$label} to")
                            ->afterStateUpdated(static function (Set $set) use ($fieldPrefix) {
                                $set("{$fieldPrefix}_date_range", 'Custom');
                            }),
                    ])
                    ->extraAttributes($hasBottomBorder ? ['class' => 'border-b border-gray-200 dark:border-white/10 pb-8'] : []),
            ])
            ->query(function (Builder $query, array $data) use ($fieldPrefix): Builder {
                $query
                    ->when($data["{$fieldPrefix}_start_date"], fn (Builder $query, $startDate) => $query->whereDate($fieldPrefix, '>=', $startDate))
                    ->when($data["{$fieldPrefix}_end_date"], fn (Builder $query, $endDate) => $query->whereDate($fieldPrefix, '<=', $endDate));

                return $query;
            })
            ->indicateUsing(function (array $data) use ($fieldPrefix, $label): array {
                $indicators = [];

                static::addIndicatorForDateRange($data, "{$fieldPrefix}_start_date", "{$fieldPrefix}_end_date", $label, $indicators);

                return $indicators;
            });

    }

    public static function addIndicatorForDateRange($data, $startKey, $endKey, $labelPrefix, &$indicators): void
    {
        $formattedStartDate = filled($data[$startKey]) ? Carbon::parse($data[$startKey])->toFormattedDateString() : null;
        $formattedEndDate = filled($data[$endKey]) ? Carbon::parse($data[$endKey])->toFormattedDateString() : null;
        if ($formattedStartDate && $formattedEndDate) {
            // If both start and end dates are set, show the combined date range as the indicator, no specific field needs to be removed since the entire filter will be removed
            $indicators[] = Tables\Filters\Indicator::make("{$labelPrefix}: {$formattedStartDate} - {$formattedEndDate}");
        } else {
            if ($formattedStartDate) {
                $indicators[] = Tables\Filters\Indicator::make("{$labelPrefix} After: {$formattedStartDate}")
                    ->removeField($startKey);
            }

            if ($formattedEndDate) {
                $indicators[] = Tables\Filters\Indicator::make("{$labelPrefix} Before: {$formattedEndDate}")
                    ->removeField($endKey);
            }
        }
    }

    protected static function determineTransactionState(Transaction $transaction, Tables\Actions\Action $action): string
    {
        if ($transaction->reviewed) {
            return 'reviewed';
        }

        if ($transaction->reviewed === false && $action->isEnabled()) {
            return 'unreviewed';
        }

        return 'uncategorized';
    }
}
