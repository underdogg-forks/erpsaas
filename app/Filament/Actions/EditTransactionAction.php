<?php

namespace App\Filament\Actions;

use App\Concerns\HasTransactionAction;
use App\Enums\Accounting\TransactionType;
use App\Models\Accounting\Transaction;
use Filament\Actions\EditAction;
use Filament\Actions\StaticAction;
use Filament\Forms\Form;
use Filament\Support\Enums\MaxWidth;

class EditTransactionAction extends EditAction
{
    use HasTransactionAction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transactionType = $this->getRecord()->type;

        $this->label(function () {
            return match ($this->transactionType) {
                TransactionType::Transfer => 'Edit transfer',
                TransactionType::Journal => 'Edit journal entry',
                default => 'Edit transaction',
            };
        });

        $this->visible(static fn (Transaction $transaction) => ! $transaction->transactionable_id);

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
                TransactionType::Transfer => 'Edit Transfer',
                default => 'Edit Transaction',
            };
        });

        $this->form(function (Form $form) {
            return match ($this->transactionType) {
                TransactionType::Transfer => $this->transferForm($form),
                TransactionType::Journal => $this->journalTransactionForm($form),
                default => $this->transactionForm($form),
            };
        });

        $this->afterFormFilled(function (Transaction $record) {
            if ($this->transactionType === TransactionType::Journal) {
                $debitAmounts = $record->journalEntries->sumDebits()->getAmount();
                $creditAmounts = $record->journalEntries->sumCredits()->getAmount();

                $this->setDebitAmount($debitAmounts);
                $this->setCreditAmount($creditAmounts);
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
    }
}
