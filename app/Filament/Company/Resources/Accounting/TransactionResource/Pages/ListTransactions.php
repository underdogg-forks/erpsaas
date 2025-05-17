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
use Filament\Support\Enums\IconSize;
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
            CreateTransactionAction::make('addIncome')
                ->label('Add income')
                ->type(TransactionType::Deposit),
            CreateTransactionAction::make('addExpense')
                ->label('Add expense')
                ->type(TransactionType::Withdrawal),
            CreateTransactionAction::make('addTransfer')
                ->label('Add transfer')
                ->type(TransactionType::Transfer),
            Actions\ActionGroup::make([
                CreateTransactionAction::make('addJournalTransaction')
                    ->label('Add journal transaction')
                    ->type(TransactionType::Journal)
                    ->groupedIcon(null),
                Actions\Action::make('connectBank')
                    ->label('Connect your bank')
                    ->visible(app(PlaidService::class)->isEnabled())
                    ->url(ConnectedAccount::getUrl()),
            ])
                ->label('More')
                ->button()
                ->outlined()
                ->dropdownWidth('max-w-fit')
                ->dropdownPlacement('bottom-end')
                ->icon('heroicon-c-chevron-down')
                ->iconSize(IconSize::Small)
                ->iconPosition(IconPosition::After),
        ];
    }
}
