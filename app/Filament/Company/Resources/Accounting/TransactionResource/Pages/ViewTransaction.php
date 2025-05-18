<?php

namespace App\Filament\Company\Resources\Accounting\TransactionResource\Pages;

use App\Filament\Actions\EditTransactionAction;
use App\Filament\Company\Resources\Accounting\TransactionResource;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\Transaction;
use Filament\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\IconSize;

class ViewTransaction extends ViewRecord
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditTransactionAction::make()
                ->label('Edit transaction')
                ->outlined(),
            Actions\ActionGroup::make([
                Actions\ActionGroup::make([
                    Actions\Action::make('markAsReviewed')
                        ->label(static fn (Transaction $record) => $record->reviewed ? 'Mark as unreviewed' : 'Mark as reviewed')
                        ->icon(static fn (Transaction $record) => $record->reviewed ? 'heroicon-s-check-circle' : 'heroicon-o-check-circle')
                        ->disabled(fn (Transaction $record): bool => $record->isUncategorized())
                        ->action(fn (Transaction $record) => $record->update(['reviewed' => ! $record->reviewed])),
                    Actions\ReplicateAction::make()
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
                Actions\DeleteAction::make(),
            ])
                ->label('Actions')
                ->button()
                ->outlined()
                ->dropdownPlacement('bottom-end')
                ->icon('heroicon-m-chevron-down')
                ->iconSize(IconSize::Small)
                ->iconPosition(IconPosition::After),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Transaction Details')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('posted_at')
                            ->label('Date')
                            ->date(),
                        TextEntry::make('type')
                            ->badge(),
                        TextEntry::make('description')
                            ->label('Description'),
                        TextEntry::make('bankAccount.account.name')
                            ->label('Account'),
                        TextEntry::make('account.name')
                            ->label('Category')
                            ->prefix(fn (Transaction $record) => $record->type->isTransfer() ? 'Transfer to ' : null)
                            ->state(fn (Transaction $record) => $record->account->name ?? 'Journal Entry'),
                        TextEntry::make('amount')
                            ->label('Amount')
                            ->weight(fn (Transaction $record) => $record->reviewed ? null : FontWeight::SemiBold)
                            ->color(
                                fn (Transaction $record) => match ($record->type) {
                                    \App\Enums\Accounting\TransactionType::Deposit => 'success',
                                    \App\Enums\Accounting\TransactionType::Journal => 'primary',
                                    default => null,
                                }
                            )
                            ->currency(fn (Transaction $record) => $record->bankAccount?->account->currency_code),
                        TextEntry::make('reviewed')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Reviewed' : 'Not Reviewed')
                            ->color(fn (bool $state): string => $state ? 'success' : 'warning'),
                        TextEntry::make('notes')
                            ->label('Notes')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function getAllRelationManagers(): array
    {
        return [
            TransactionResource\RelationManagers\JournalEntriesRelationManager::class,
        ];
    }
}
