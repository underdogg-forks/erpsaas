<?php

namespace App\Filament\Company\Resources\Accounting\TransactionResource\Pages;

use App\Concerns\HasJournalEntryActions;
use App\Enums\Accounting\TransactionType;
use App\Filament\Actions\CreateTransactionAction;
use App\Filament\Company\Pages\Service\ConnectedAccount;
use App\Filament\Company\Resources\Accounting\TransactionResource;
use App\Services\PlaidService;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\MaxWidth;

class ListTransactions extends ListRecords
{
    use HasJournalEntryActions;

    protected static string $resource = TransactionResource::class;

    public function getMaxContentWidth(): MaxWidth | string | null
    {
        return 'max-w-8xl';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                CreateTransactionAction::make('createDeposit')
                    ->label('Deposit')
                    ->type(TransactionType::Deposit),
                CreateTransactionAction::make('createWithdrawal')
                    ->label('Withdrawal')
                    ->type(TransactionType::Withdrawal),
                CreateTransactionAction::make('createTransfer')
                    ->label('Transfer')
                    ->type(TransactionType::Transfer),
                CreateTransactionAction::make('createJournalEntry')
                    ->label('Journal entry')
                    ->type(TransactionType::Journal),
            ])
                ->label('New transaction')
                ->button()
                ->dropdownPlacement('bottom-end')
                ->icon('heroicon-m-chevron-down')
                ->iconPosition(IconPosition::After),
            Actions\ActionGroup::make([
                Actions\Action::make('connectBank')
                    ->label('Connect your bank')
                    ->visible(app(PlaidService::class)->isEnabled())
                    ->url(ConnectedAccount::getUrl()),
            ])
                ->label('More')
                ->button()
                ->outlined()
                ->dropdownPlacement('bottom-end')
                ->icon('heroicon-m-chevron-down')
                ->iconPosition(IconPosition::After),
        ];
    }
}
