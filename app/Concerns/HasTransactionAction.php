<?php

namespace App\Concerns;

use App\Enums\Accounting\JournalEntryType;
use App\Enums\Accounting\TransactionType;
use App\Filament\Forms\Components\CustomTableRepeater;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Transaction;
use App\Models\Banking\BankAccount;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use Awcodes\TableRepeater\Header;
use Closure;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Form;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

trait HasTransactionAction
{
    use HasJournalEntryActions;

    protected TransactionType | Closure | null $transactionType = null;

    public function type(TransactionType | Closure | null $type = null): static
    {
        $this->transactionType = $type;

        return $this;
    }

    public function getTransactionType(): ?TransactionType
    {
        return $this->evaluate($this->transactionType);
    }

    protected function getFormDefaultsForType(TransactionType $type): array
    {
        $commonDefaults = [
            'posted_at' => today(),
        ];

        return match ($type) {
            TransactionType::Deposit, TransactionType::Withdrawal, TransactionType::Transfer => array_merge($commonDefaults, $this->transactionDefaults($type)),
            TransactionType::Journal => array_merge($commonDefaults, $this->journalEntryDefaults()),
        };
    }

    protected function journalEntryDefaults(): array
    {
        return [
            'journalEntries' => [
                $this->defaultEntry(JournalEntryType::Debit),
                $this->defaultEntry(JournalEntryType::Credit),
            ],
        ];
    }

    protected function defaultEntry(JournalEntryType $journalEntryType): array
    {
        return [
            'type' => $journalEntryType,
            'account_id' => Transaction::getUncategorizedAccountByType($journalEntryType->isDebit() ? TransactionType::Withdrawal : TransactionType::Deposit)?->id,
            'amount' => '0.00',
        ];
    }

    protected function transactionDefaults(TransactionType $type): array
    {
        return [
            'type' => $type,
            'bank_account_id' => BankAccount::where('enabled', true)->first()?->id,
            'amount' => '0.00',
            'account_id' => ! $type->isTransfer() ? Transaction::getUncategorizedAccountByType($type)->id : null,
        ];
    }

    public function transactionForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('posted_at')
                    ->label('Date')
                    ->required(),
                Forms\Components\TextInput::make('description')
                    ->label('Description'),
                Forms\Components\Select::make('bank_account_id')
                    ->label('Account')
                    ->options(fn (?Transaction $transaction) => Transaction::getBankAccountOptions(currentBankAccountId: $transaction?->bank_account_id))
                    ->live()
                    ->searchable()
                    ->afterStateUpdated(function (Forms\Set $set, $state, $old, Forms\Get $get) {
                        $amount = CurrencyConverter::convertAndSet(
                            BankAccount::find($state)->account->currency_code,
                            BankAccount::find($old)->account->currency_code ?? CurrencyAccessor::getDefaultCurrency(),
                            $get('amount')
                        );

                        if ($amount !== null) {
                            $set('amount', $amount);
                        }
                    })
                    ->required(),
                Forms\Components\Select::make('type')
                    ->label('Type')
                    ->live()
                    ->options([
                        TransactionType::Deposit->value => TransactionType::Deposit->getLabel(),
                        TransactionType::Withdrawal->value => TransactionType::Withdrawal->getLabel(),
                    ])
                    ->required()
                    ->afterStateUpdated(static fn (Forms\Set $set, $state) => $set('account_id', Transaction::getUncategorizedAccountByType(TransactionType::parse($state))?->id)),
                Forms\Components\TextInput::make('amount')
                    ->label('Amount')
                    ->money(static fn (Forms\Get $get) => BankAccount::find($get('bank_account_id'))?->account?->currency_code ?? CurrencyAccessor::getDefaultCurrency())
                    ->required(),
                Forms\Components\Select::make('account_id')
                    ->label('Category')
                    ->options(fn (Forms\Get $get, ?Transaction $transaction) => Transaction::getTransactionAccountOptions(type: TransactionType::parse($get('type')), currentAccountId: $transaction?->account_id))
                    ->searchable()
                    ->required(),
                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->autosize()
                    ->rows(10)
                    ->columnSpanFull(),
            ])
            ->columns();
    }

    public function transferForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('posted_at')
                    ->label('Date')
                    ->required(),
                Forms\Components\TextInput::make('description')
                    ->label('Description'),
                Forms\Components\Select::make('bank_account_id')
                    ->label('From account')
                    ->options(fn (Forms\Get $get, ?Transaction $transaction) => Transaction::getBankAccountOptions(excludedAccountId: $get('account_id'), currentBankAccountId: $transaction?->bank_account_id))
                    ->live()
                    ->searchable()
                    ->afterStateUpdated(function (Forms\Set $set, $state, $old, Forms\Get $get) {
                        $amount = CurrencyConverter::convertAndSet(
                            BankAccount::find($state)->account->currency_code,
                            BankAccount::find($old)->account->currency_code ?? CurrencyAccessor::getDefaultCurrency(),
                            $get('amount')
                        );

                        if ($amount !== null) {
                            $set('amount', $amount);
                        }
                    })
                    ->required(),
                Forms\Components\Select::make('type')
                    ->label('Type')
                    ->options([
                        TransactionType::Transfer->value => TransactionType::Transfer->getLabel(),
                    ])
                    ->disabled()
                    ->dehydrated()
                    ->required(),
                Forms\Components\TextInput::make('amount')
                    ->label('Amount')
                    ->money(static fn (Forms\Get $get) => BankAccount::find($get('bank_account_id'))?->account?->currency_code ?? CurrencyAccessor::getDefaultCurrency())
                    ->required(),
                Forms\Components\Select::make('account_id')
                    ->label('To account')
                    ->live()
                    ->options(fn (Forms\Get $get, ?Transaction $transaction) => Transaction::getBankAccountAccountOptions(excludedBankAccountId: $get('bank_account_id'), currentAccountId: $transaction?->account_id))
                    ->searchable()
                    ->required(),
                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->autosize()
                    ->rows(10)
                    ->columnSpanFull(),
            ])
            ->columns();
    }

    public function journalTransactionForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Tabs')
                    ->contained(false)
                    ->tabs([
                        $this->getJournalTransactionFormEditTab(),
                        $this->getJournalTransactionFormNotesTab(),
                    ]),
            ])
            ->columns(1);
    }

    protected function getJournalTransactionFormEditTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Edit')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->schema([
                $this->getTransactionDetailsGrid(),
                $this->getJournalEntriesTableRepeater(),
            ]);
    }

    protected function getJournalTransactionFormNotesTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Notes')
            ->label('Notes')
            ->icon('heroicon-o-clipboard')
            ->id('notes')
            ->schema([
                $this->getTransactionDetailsGrid(),
                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->rows(10)
                    ->autosize(),
            ]);
    }

    protected function getTransactionDetailsGrid(): Forms\Components\Grid
    {
        return Forms\Components\Grid::make(8)
            ->schema([
                Forms\Components\DatePicker::make('posted_at')
                    ->label('Date')
                    ->softRequired()
                    ->displayFormat('Y-m-d'),
                Forms\Components\TextInput::make('description')
                    ->label('Description')
                    ->columnSpan(2),
            ]);
    }

    protected function getJournalEntriesTableRepeater(): CustomTableRepeater
    {
        return CustomTableRepeater::make('journalEntries')
            ->relationship('journalEntries')
            ->hiddenLabel()
            ->columns(4)
            ->headers($this->getJournalEntriesTableRepeaterHeaders())
            ->schema($this->getJournalEntriesTableRepeaterSchema())
            ->deletable(fn (CustomTableRepeater $repeater) => $repeater->getItemsCount() > 2)
            ->deleteAction(function (Forms\Components\Actions\Action $action) {
                return $action
                    ->action(function (array $arguments, CustomTableRepeater $component): void {
                        $items = $component->getState();

                        $amount = $items[$arguments['item']]['amount'];
                        $type = $items[$arguments['item']]['type'];

                        $this->updateJournalEntryAmount(JournalEntryType::parse($type), '0.00', $amount);

                        unset($items[$arguments['item']]);

                        $component->state($items);

                        $component->callAfterStateUpdated();
                    });
            })
            ->rules([
                function () {
                    return function (string $attribute, $value, \Closure $fail) {
                        if (empty($value) || ! is_array($value)) {
                            $fail('Journal entries are required.');

                            return;
                        }

                        $hasDebit = false;
                        $hasCredit = false;

                        foreach ($value as $entry) {
                            if (! isset($entry['type'])) {
                                continue;
                            }

                            if (JournalEntryType::parse($entry['type'])->isDebit()) {
                                $hasDebit = true;
                            } elseif (JournalEntryType::parse($entry['type'])->isCredit()) {
                                $hasCredit = true;
                            }

                            if ($hasDebit && $hasCredit) {
                                break;
                            }
                        }

                        if (! $hasDebit) {
                            $fail('At least one debit entry is required.');
                        }

                        if (! $hasCredit) {
                            $fail('At least one credit entry is required.');
                        }
                    };
                },
            ])
            ->minItems(2)
            ->defaultItems(2)
            ->addable(false)
            ->footerItem(fn (): View => $this->getJournalTransactionModalFooter())
            ->extraActions([
                $this->buildAddJournalEntryAction(JournalEntryType::Debit),
                $this->buildAddJournalEntryAction(JournalEntryType::Credit),
            ]);
    }

    protected function getJournalEntriesTableRepeaterHeaders(): array
    {
        return [
            Header::make('type')
                ->width('150px')
                ->label('Type'),
            Header::make('description')
                ->width('320px')
                ->label('Description'),
            Header::make('account_id')
                ->width('320px')
                ->label('Account'),
            Header::make('amount')
                ->width('192px')
                ->label('Amount'),
        ];
    }

    protected function getJournalEntriesTableRepeaterSchema(): array
    {
        return [
            Forms\Components\Select::make('type')
                ->label('Type')
                ->options(JournalEntryType::class)
                ->live()
                ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set, $state, $old) {
                    $this->adjustJournalEntryAmountsForTypeChange(JournalEntryType::parse($state), JournalEntryType::parse($old), $get('amount'));
                })
                ->softRequired(),
            Forms\Components\TextInput::make('description')
                ->label('Description'),
            Forms\Components\Select::make('account_id')
                ->label('Account')
                ->options(fn (?JournalEntry $journalEntry): array => Transaction::getJournalAccountOptions(currentAccountId: $journalEntry?->account_id))
                ->softRequired()
                ->searchable(),
            Forms\Components\TextInput::make('amount')
                ->label('Amount')
                ->live()
                ->mask(moneyMask(CurrencyAccessor::getDefaultCurrency()))
                ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set, ?string $state, ?string $old) {
                    $this->updateJournalEntryAmount(JournalEntryType::parse($get('type')), $state, $old);
                })
                ->softRequired(),
        ];
    }

    protected function buildAddJournalEntryAction(JournalEntryType $type): FormAction
    {
        $typeLabel = $type->getLabel();

        return FormAction::make("add{$typeLabel}Entry")
            ->button()
            ->outlined()
            ->color($type->isDebit() ? 'primary' : 'gray')
            ->action(function (CustomTableRepeater $component) use ($type) {
                $state = $component->getState();
                $newUuid = (string) Str::uuid();
                $state[$newUuid] = $this->defaultEntry($type);

                $component->state($state);
            });
    }

    public function getJournalTransactionModalFooter(): View
    {
        return view(
            'filament.company.components.actions.journal-entry-footer',
            [
                'debitAmount' => $this->getFormattedDebitAmount(),
                'creditAmount' => $this->getFormattedCreditAmount(),
                'difference' => $this->getFormattedBalanceDifference(),
                'isJournalBalanced' => $this->isJournalEntryBalanced(),
            ],
        );
    }
}
