<?php

namespace App\Filament\Tables\Actions;

use App\Concerns\HasTransactionAction;
use App\Enums\Accounting\TransactionType;
use App\Models\Accounting\Transaction;
use Filament\Actions\StaticAction;
use Filament\Forms\Form;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Actions\EditAction;

class EditTransactionAction extends EditAction
{
    use HasTransactionAction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->type(static function (Transaction $record) {
            return $record->type;
        });

        $this->label(function () {
            return match ($this->getTransactionType()) {
                TransactionType::Journal => 'Edit journal entry',
                default => 'Edit transaction',
            };
        });

        $this->slideOver();

        $this->modalWidth(function (): MaxWidth {
            return match ($this->getTransactionType()) {
                TransactionType::Journal => MaxWidth::Screen,
                default => MaxWidth::ThreeExtraLarge,
            };
        });

        $this->extraModalWindowAttributes(function (): array {
            if ($this->getTransactionType() === TransactionType::Journal) {
                return ['class' => 'journal-transaction-modal'];
            }

            return [];
        });

        $this->form(function (Form $form) {
            return match ($this->getTransactionType()) {
                TransactionType::Transfer => $this->transferForm($form),
                TransactionType::Journal => $this->journalTransactionForm($form),
                default => $this->transactionForm($form),
            };
        });

        $this->afterFormFilled(function (Transaction $record) {
            if ($this->getTransactionType() === TransactionType::Journal) {
                $debitAmounts = $record->journalEntries->sumDebits()->getAmount();
                $creditAmounts = $record->journalEntries->sumCredits()->getAmount();

                $this->setDebitAmount($debitAmounts);
                $this->setCreditAmount($creditAmounts);
            }
        });

        $this->modalSubmitAction(function (StaticAction $action) {
            if ($this->getTransactionType() === TransactionType::Journal) {
                $action->disabled(! $this->isJournalEntryBalanced());
            }

            return $action;
        });

        $this->after(function (Transaction $transaction) {
            if ($this->getTransactionType() === TransactionType::Journal) {
                $transaction->updateAmountIfBalanced();
            }
        });
    }
}
