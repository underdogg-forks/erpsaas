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

        $this->label(null);

        $this->groupedIcon(null);

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

        $this->modalHeading(function (): string {
            return match ($this->getTransactionType()) {
                TransactionType::Journal => 'Create journal entry',
                default => 'Create transaction',
            };
        });

        $this->fillForm(fn (): array => $this->getFormDefaultsForType($this->getTransactionType()));

        $this->form(function (Form $form) {
            return match ($this->getTransactionType()) {
                TransactionType::Transfer => $this->transferForm($form),
                TransactionType::Journal => $this->journalTransactionForm($form),
                default => $this->transactionForm($form),
            };
        });

        $this->afterFormFilled(function () {
            if ($this->getTransactionType() === TransactionType::Journal) {
                $this->resetJournalEntryAmounts();
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

        $this->mutateFormDataUsing(function (array $data) {
            if ($this->getTransactionType() === TransactionType::Journal) {
                $data['type'] = TransactionType::Journal;
            }

            return $data;
        });

        $this->outlined(fn () => ! $this->getGroup());
    }
}
