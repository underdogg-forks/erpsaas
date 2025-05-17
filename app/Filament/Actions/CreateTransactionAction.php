<?php

namespace App\Filament\Actions;

use App\Concerns\HasTransactionAction;
use App\Enums\Accounting\TransactionType;
use App\Models\Accounting\Transaction;
use Filament\Actions\CreateAction;
use Filament\Actions\StaticAction;
use Filament\Forms\Form;
use Filament\Support\Enums\MaxWidth;

class CreateTransactionAction extends CreateAction
{
    use HasTransactionAction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modalWidth(function (): MaxWidth {
            return match ($this->transactionType) {
                TransactionType::Journal => MaxWidth::Screen,
                default => MaxWidth::ThreeExtraLarge,
            };
        });

        $this->extraModalWindowAttributes(function (): array {
            if ($this->transactionType === TransactionType::Journal) {
                return ['class' => 'journal-transaction-modal'];
            }

            return [];
        });

        $this->modalHeading(function (): string {
            return match ($this->transactionType) {
                TransactionType::Journal => 'Journal Entry',
                TransactionType::Deposit => 'Add Income',
                TransactionType::Withdrawal => 'Add Expense',
                TransactionType::Transfer => 'Add Transfer',
                default => 'Add Transaction',
            };
        });

        $this->fillForm(fn (): array => $this->getFormDefaultsForType($this->transactionType));

        $this->form(function (Form $form) {
            return match ($this->transactionType) {
                TransactionType::Transfer => $this->transferForm($form),
                TransactionType::Journal => $this->journalTransactionForm($form),
                default => $this->transactionForm($form),
            };
        });

        $this->afterFormFilled(function () {
            if ($this->transactionType === TransactionType::Journal) {
                $this->resetJournalEntryAmounts();
            }
        });

        $this->modalSubmitAction(function (StaticAction $action) {
            if ($this->transactionType === TransactionType::Journal) {
                $action->disabled(! $this->isJournalEntryBalanced());
            }

            return $action;
        });

        $this->after(function (Transaction $transaction) {
            if ($this->transactionType === TransactionType::Journal) {
                $transaction->updateAmountIfBalanced();
            }
        });

        $this->mutateFormDataUsing(function (array $data) {
            if ($this->transactionType === TransactionType::Journal) {
                $data['type'] = TransactionType::Journal;
            }

            return $data;
        });

        $this->outlined(fn () => ! $this->getGroup());
    }
}
